// SortableJS is provided by admin-vendor.js (bundle mode) or the CDN
// script (CDN mode) and consumed here as a global, so the page never
// ends up with two Sortable copies.
const Sortable = window.Sortable;

/**
 * Reusable Grid Layout Drag & Drop Manager
 *
 * Features: cross-row drag & drop (SortableJS), per-card width presets
 * (12-column grid), per-card height resize, keyboard reordering,
 * localStorage persistence (v2 schema, legacy v1 migrated on read).
 */
export class GridLayoutManager {
    constructor(options = {}) {
        this.container = typeof options.container === 'string'
            ? document.querySelector(options.container)
            : options.container;

        if (!this.container) return;

        this.storageKey = options.storageKey || 'vl_grid_layout_order';
        this.cardSelector = options.cardSelector || '[data-card-id]';
        this.cardIdAttr = options.cardIdAttr || 'data-card-id';
        this.handleSelector = options.handleSelector || '.drag-handle';
        this.groupName = options.groupName || 'analytics-rows';
        this.rowClass = options.rowClass || 'analytics-row grid gap-4';
        this.rowDefinitions = options.rowDefinitions || [];
        this.cardSpans = options.cardSpans || {};
        this.defaultSpan = options.defaultSpan || 4;
        this.fullSpan = 12;
        this.fitTargetSelector = options.fitTargetSelector || '[class*="max-h-"], [data-vl-fit], .analytics-chart-wrap';
        this.minScrollFit = options.minScrollFit || 120;
        this.onOrderChange = typeof options.onOrderChange === 'function' ? options.onOrderChange : null;

        this.spanPresets = [
            { value: 3, label: 'Narrow' },
            { value: 4, label: 'Third' },
            { value: 6, label: 'Half' },
            { value: 8, label: 'Wide' },
            { value: 12, label: 'Full' },
        ];

        this.rows = {};
        this.cards = {};
        this.sizes = {};
        this.heights = {};
        this.sortableInstances = new Map();
        this.isProcessing = false;
        this.layoutDebounce = null;

        this.init();
    }

    __(key) {
        return (window.VL_I18N && window.VL_I18N[key]) ? window.VL_I18N[key] : key;
    }

    init() {
        this.collectCardsAndBuildRows();
        this.restoreOrder();
        this.mergeNewCards();
        this.applySizes();
        this.applyHeights();
        this.setupControls();
        this.setupDropZones();
        this.initSortables();
        this.refreshAllRowLayouts();
        this.observeLayoutChanges();
        this.setupResetButton();
        this.updateResetButtonVisibility();
        this.fitAllScrollAreas();
    }

    observeLayoutChanges() {
        this.layoutObserver = new MutationObserver(() => {
            if (this.isProcessing) return;
            clearTimeout(this.layoutDebounce);
            this.layoutDebounce = setTimeout(() => this.refreshAllRowLayouts(), 120);
        });
        this.layoutObserver.observe(this.container, { childList: true });

        this.viewportTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(this.viewportTimer);
            this.viewportTimer = setTimeout(() => {
                this.fitAllScrollAreas();
                this.resizeCharts();
            }, 150);
        });
        window.addEventListener('load', () => {
            this.fitAllScrollAreas();
            this.refreshCharts();
        });
    }

    collectCardsAndBuildRows() {
        const cardElements = Array.from(this.container.querySelectorAll(this.cardSelector));
        cardElements.forEach(card => {
            const id = card.getAttribute(this.cardIdAttr);
            if (id) {
                this.cards[id] = card;
            }
        });

        cardElements.forEach(card => {
            const id = card.getAttribute(this.cardIdAttr);
            if (id && id.startsWith('top-cpt-')) {
                const exists = this.rowDefinitions.some(def => def.id === id);
                if (!exists) {
                    this.rowDefinitions.push({ id: id, cards: [id] });
                }
            }
        });

        this.rowDefinitions.forEach(definition => {
            const row = document.createElement('div');
            row.dataset.rowId = definition.id;
            row.className = this.rowClass;
            this.rows[definition.id] = row;

            definition.cards.forEach(id => {
                if (this.cards[id]) {
                    row.appendChild(this.cards[id]);
                }
            });

            if (row.children.length > 0) {
                this.container.appendChild(row);
            }
        });
    }

    defaultRowFor(cardId) {
        const def = this.rowDefinitions.find(d => d.cards.includes(cardId));
        return def ? def.id : null;
    }

    defaultSpanFor(cardId) {
        if (this.cardSpans[cardId]) return this.cardSpans[cardId];
        if (cardId.startsWith('top-cpt-')) return this.fullSpan;
        return this.defaultSpan;
    }

    spanFor(cardId) {
        const saved = parseInt(this.sizes[cardId], 10);
        if (this.spanPresets.some(p => p.value === saved)) return saved;
        return this.defaultSpanFor(cardId);
    }

    setupDropZones() {
        let dropZone = this.container.querySelector('.analytics-dropzone[data-row-id="__dropzone"]');
        if (!dropZone) {
            dropZone = document.createElement('div');
            dropZone.className = 'analytics-dropzone';
            dropZone.dataset.rowId = '__dropzone';
            this.container.appendChild(dropZone);
        }
        this.dropZone = dropZone;

        this.rebuildInterDropZoneDOM();
    }

    rebuildInterDropZoneDOM() {
        const rowElements = Array.from(this.container.children).filter(el => {
            return el.dataset && el.dataset.rowId && el.dataset.rowId !== '__dropzone' && !el.dataset.interDrop;
        });

        rowElements.forEach(row => {
            const prev = row.previousElementSibling;
            if (!prev || !prev.dataset.interDrop) {
                const dz = document.createElement('div');
                dz.className = 'analytics-inter-drop';
                dz.dataset.interDrop = '1';
                this.container.insertBefore(dz, row);
            }
        });

        Array.from(this.container.querySelectorAll('.analytics-inter-drop')).forEach(dz => {
            const next = dz.nextElementSibling;
            if (!next || !next.dataset || !next.dataset.rowId || next.dataset.rowId === '__dropzone') {
                this.destroySortable(dz);
                dz.remove();
            }
        });
    }

    initSortables() {
        if (!Sortable) return;
        Object.values(this.rows).forEach(row => {
            this.initRowSortable(row);
        });

        this.initDropZoneSortable(this.dropZone);
        this.refreshInterDropSortables();
    }

    refreshInterDropSortables() {
        if (!Sortable) return;
        Array.from(this.container.querySelectorAll('.analytics-inter-drop')).forEach(dz => {
            if (!this.sortableInstances.has(dz)) {
                this.initInterDropSortable(dz);
            }
        });
    }

    initRowSortable(row) {
        if (this.sortableInstances.has(row)) return;

        const inst = Sortable.create(row, {
            group: { name: this.groupName, pull: true, put: true },
            handle: this.handleSelector,
            direction: 'horizontal',
            animation: 150,
            swapThreshold: 0.35,
            invertedSwapThreshold: 0.65,
            emptyInsertThreshold: 24,
            delay: 120,
            delayOnTouchOnly: true,
            fallbackOnBody: true,
            ghostClass: 'analytics-ghost',
            chosenClass: 'analytics-chosen',
            onMove: evt => {
                this.cleanDragOver();
                if (evt.to) {
                    evt.to.classList.add('analytics-drag-over');
                }
            },
            onAdd: () => {
                this.scheduleReconciliation();
            },
            onRemove: () => {
                this.scheduleReconciliation();
            },
            onEnd: () => {
                this.cleanDragOver();
                this.scheduleReconciliation();
            }
        });

        this.sortableInstances.set(row, inst);
    }

    initDropZoneSortable(dz) {
        if (!dz || this.sortableInstances.has(dz)) return;

        const inst = Sortable.create(dz, {
            group: { name: this.groupName, pull: false, put: true },
            handle: this.handleSelector,
            direction: 'vertical',
            emptyInsertThreshold: 24,
            ghostClass: 'analytics-ghost',
            onMove: evt => {
                this.cleanDragOver();
                if (evt.to) evt.to.classList.add('analytics-drag-over');
            },
            onAdd: evt => {
                this.cleanDragOver();
                this.handleCardDroppedInZone(evt, dz);
            },
            onEnd: () => {
                this.cleanDragOver();
                this.scheduleReconciliation();
            }
        });

        this.sortableInstances.set(dz, inst);
    }

    initInterDropSortable(dz) {
        if (this.sortableInstances.has(dz)) return;

        const inst = Sortable.create(dz, {
            group: { name: this.groupName, pull: false, put: true },
            direction: 'vertical',
            ghostClass: 'analytics-ghost',
            onMove: evt => {
                this.cleanDragOver();
                if (evt.to) evt.to.classList.add('analytics-drag-over');
            },
            onAdd: evt => {
                this.cleanDragOver();
                this.handleCardDroppedInZone(evt, dz);
            },
            onEnd: () => {
                this.cleanDragOver();
                this.scheduleReconciliation();
            }
        });

        this.sortableInstances.set(dz, inst);
    }

    handleCardDroppedInZone(evt, zone) {
        const item = evt.item;
        if (!item) return;

        setTimeout(() => {
            const newId = 'dynamic-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
            const row = document.createElement('div');
            row.dataset.rowId = newId;
            row.className = this.rowClass;
            row.appendChild(item);

            if (zone.parentNode === this.container) {
                this.container.insertBefore(row, zone);
            } else {
                this.container.appendChild(row);
            }

            this.rows[newId] = row;
            this.initRowSortable(row);

            this.reconcileGridState();
        }, 0);
    }

    scheduleReconciliation() {
        if (this.isProcessing) return;
        this.isProcessing = true;

        setTimeout(() => {
            this.isProcessing = false;
            this.reconcileGridState();
        }, 0);
    }

    reconcileGridState() {
        this.cleanDragOver();
        this.pruneEmptyRows();
        this.refreshAllRowLayouts();
        this.rebuildInterDropZoneDOM();
        this.refreshInterDropSortables();
        this.saveOrder();
        this.updateResetButtonVisibility();
        this.fitAllScrollAreas();
        this.refreshCharts();

        if (this.onOrderChange) {
            this.onOrderChange();
        }
    }

    pruneEmptyRows() {
        Object.entries(this.rows).forEach(([id, row]) => {
            if (row.children.length === 0 && row.parentNode) {
                this.destroySortable(row);
                row.remove();
                delete this.rows[id];
            }
        });
    }

    refreshAllRowLayouts() {
        Object.values(this.rows).forEach(row => this.refreshRowLayout(row));
    }

    refreshRowLayout(row) {
        row.style.removeProperty('--analytics-columns');
        delete row.dataset.layout;
    }

    orderedRows() {
        return Array.from(this.container.children).filter(el => {
            return el.dataset && el.dataset.rowId && el.dataset.rowId !== '__dropzone' && !el.dataset.interDrop;
        });
    }

    moveCardKeyboard(card, dir) {
        const row = card.parentElement;
        if (!row || !row.dataset || !row.dataset.rowId) return;

        if (dir === 'left' || dir === 'right') {
            const siblings = Array.from(row.children).filter(el => el.matches(this.cardSelector));
            const idx = siblings.indexOf(card);
            const target = siblings[idx + (dir === 'left' ? -1 : 1)];
            if (target) {
                row.insertBefore(card, dir === 'left' ? target : target.nextSibling);
                this.scheduleReconciliation();
            }
            return;
        }

        const rows = this.orderedRows();
        const idx = rows.indexOf(row);
        const target = rows[idx + (dir === 'up' ? -1 : 1)];
        if (target) {
            target.appendChild(card);
            this.scheduleReconciliation();
        }
    }

    outerHeight(el) {
        const style = window.getComputedStyle(el);
        if (style.position === 'absolute' || style.position === 'fixed' || el.hidden) return 0;
        return el.offsetHeight + parseFloat(style.marginTop || 0) + parseFloat(style.marginBottom || 0);
    }

    fitScrollAreas(card) {
        const targets = card.querySelectorAll(this.fitTargetSelector);
        if (!targets.length) return;
        const cardStyle = window.getComputedStyle(card);
        const padV = parseFloat(cardStyle.paddingTop || 0) + parseFloat(cardStyle.paddingBottom || 0);
        targets.forEach(target => {
            let used = padV;
            const container = target.parentElement;
            Array.from(container.children).forEach(sib => {
                if (sib !== target) used += this.outerHeight(sib);
            });
            let el = container;
            while (el && el !== card) {
                const parent = el.parentElement;
                if (!parent) break;
                Array.from(parent.children).forEach(sib => {
                    if (sib !== el) used += this.outerHeight(sib);
                });
                el = parent;
            }
            // The grip is absolutely positioned, so nothing is reserved for it:
            // a reserve made the target shorter than the space it fills, and
            // every fit (resize, load, mutation) shrank the card a little more
            // until it collapsed to the floor. Filling the measured space makes
            // the fit idempotent — a card without a stored height keeps its
            // height, and a card with one grows its list to fill the pinned box.
            const avail = Math.max(this.minScrollFit, card.clientHeight - used);
            if (target.classList.contains('analytics-chart-wrap')) {
                target.style.height = avail + 'px';
            } else {
                target.style.maxHeight = avail + 'px';
            }
        });
    }

    clearScrollFits(card) {
        card.querySelectorAll(this.fitTargetSelector).forEach(target => {
            target.style.removeProperty('max-height');
            target.style.removeProperty('height');
        });
    }

    fitAllScrollAreas() {
        Object.values(this.cards).forEach(card => this.fitScrollAreas(card));
    }

    setupControls() {
        Object.entries(this.cards).forEach(([id, card]) => {
            this.setupHandleA11y(card);
            this.setupSizeControl(id, card);
            this.setupResizeHandle(id, card);
        });
    }

    setupHandleA11y(card) {
        const handle = card.querySelector(this.handleSelector);
        if (!handle || handle.hasAttribute('data-a11y')) return;
        handle.setAttribute('data-a11y', '1');
        handle.setAttribute('tabindex', '0');
        handle.setAttribute('role', 'button');
        handle.setAttribute('aria-label', this.__('Drag to reorder'));
        handle.addEventListener('keydown', evt => {
            const map = {
                ArrowLeft: 'left',
                ArrowRight: 'right',
                ArrowUp: 'up',
                ArrowDown: 'down',
            };
            if (map[evt.key]) {
                evt.preventDefault();
                this.moveCardKeyboard(card, map[evt.key]);
            } else if (evt.key === 'Home') {
                evt.preventDefault();
                const row = card.parentElement;
                if (row) row.insertBefore(card, row.firstElementChild);
                this.scheduleReconciliation();
            } else if (evt.key === 'End') {
                evt.preventDefault();
                const row = card.parentElement;
                if (row) row.appendChild(card);
                this.scheduleReconciliation();
            }
        });
    }

    setupSizeControl(id, card) {
        if (card.querySelector('[data-card-size]')) return;
        const header = card.querySelector(':scope > div.flex');
        if (!header) return;

        const wrap = document.createElement('label');
        wrap.className = 'analytics-card-size-wrap';
        wrap.setAttribute('data-card-size-wrap', '');

        const caption = document.createElement('span');
        caption.textContent = this.__('Card width');
        caption.className = 'analytics-card-size-caption';
        wrap.appendChild(caption);

        const select = document.createElement('select');
        select.setAttribute('data-card-size', '');
        select.setAttribute('aria-label', this.__('Card width'));
        select.className = 'analytics-card-size-select';
        this.spanPresets.forEach(preset => {
            const opt = document.createElement('option');
            opt.value = String(preset.value);
            opt.textContent = this.__(preset.label);
            select.appendChild(opt);
        });
        select.value = String(this.spanFor(id));
        select.addEventListener('change', () => {
            this.sizes[id] = parseInt(select.value, 10);
            this.applySizes();
            this.saveOrder();
            this.updateResetButtonVisibility();
            this.refreshCharts();
        });
        select.addEventListener('pointerdown', evt => evt.stopPropagation());
        select.addEventListener('mousedown', evt => evt.stopPropagation());
        wrap.appendChild(select);

        header.appendChild(wrap);
    }

    setupResizeHandle(id, card) {
        if (card.querySelector('[data-card-resize]')) return;

        const grip = document.createElement('div');
        grip.setAttribute('data-card-resize', '');
        grip.className = 'analytics-resize-handle';
        grip.title = this.__('Drag to resize height');
        grip.setAttribute('aria-hidden', 'true');
        card.appendChild(grip);

        grip.addEventListener('dblclick', () => {
            delete this.heights[id];
            this.applyHeights();
            this.saveOrder();
            this.updateResetButtonVisibility();
            this.refreshCharts();
        });

        grip.addEventListener('pointerdown', evt => {
            evt.preventDefault();
            evt.stopPropagation();
            const startY = evt.clientY;
            const startH = card.offsetHeight;
            grip.setPointerCapture(evt.pointerId);

            const onMove = moveEvt => {
                const next = Math.min(1200, Math.max(140, startH + (moveEvt.clientY - startY)));
                card.style.minHeight = next + 'px';
                card.classList.add('analytics-custom-height');
                this.fitScrollAreas(card);
            };
            const onUp = () => {
                grip.removeEventListener('pointermove', onMove);
                grip.removeEventListener('pointerup', onUp);
                grip.removeEventListener('pointercancel', onUp);
                const finalH = parseInt(card.style.minHeight, 10);
                if (Number.isFinite(finalH)) {
                    this.heights[id] = finalH;
                    this.saveOrder();
                    this.updateResetButtonVisibility();
                }
                this.refreshCharts();
            };
            grip.addEventListener('pointermove', onMove);
            grip.addEventListener('pointerup', onUp);
            grip.addEventListener('pointercancel', onUp);
        });
    }

    applySizes() {
        Object.entries(this.cards).forEach(([id, card]) => {
            card.style.setProperty('--card-span', String(this.spanFor(id)));
            const select = card.querySelector('[data-card-size]');
            if (select) select.value = String(this.spanFor(id));
        });
    }

    applyHeights() {
        Object.entries(this.cards).forEach(([id, card]) => {
            const h = parseInt(this.heights[id], 10);
            if (Number.isFinite(h) && h > 0) {
                card.style.minHeight = h + 'px';
                card.classList.add('analytics-custom-height');
                this.fitScrollAreas(card);
            } else {
                card.style.removeProperty('min-height');
                card.classList.remove('analytics-custom-height');
                this.clearScrollFits(card);
            }
        });
    }

    refreshCharts() {
        try {
            window.dispatchEvent(new Event('resize'));
        } catch (e) {}
        this.resizeCharts();
    }

    /**
     * Re-sizes the Chart.js instances after a layout change. Split out of
     * refreshCharts() so the window "resize" handler can call it without
     * dispatching a resize event — that dispatch re-entered the handler
     * (resize → fit/charts → resize) and looped forever.
     */
    resizeCharts() {
        requestAnimationFrame(() => {
            try {
                if (!window.Chart || typeof window.Chart.getChart !== 'function') return;
                this.container.querySelectorAll('canvas').forEach(canvas => {
                    const chart = window.Chart.getChart(canvas);
                    if (chart) chart.resize();
                });
            } catch (e) {}
        });
    }

    cleanDragOver() {
        Object.values(this.rows).forEach(r => r.classList.remove('analytics-drag-over'));
        this.container.querySelectorAll('.analytics-inter-drop').forEach(d => d.classList.remove('analytics-drag-over'));
        if (this.dropZone) {
            this.dropZone.classList.remove('analytics-drag-over');
        }
    }

    destroySortable(el) {
        if (this.sortableInstances.has(el)) {
            try {
                this.sortableInstances.get(el).destroy();
            } catch (e) {}
            this.sortableInstances.delete(el);
        }
    }

    saveOrder() {
        const payload = { version: 2, _rowOrder: [], rows: {}, sizes: {}, heights: {} };
        Array.from(this.container.children).forEach(child => {
            const rowId = child.dataset.rowId;
            if (!rowId || rowId === '__dropzone' || child.dataset.interDrop) return;
            payload._rowOrder.push(rowId);
            payload.rows[rowId] = Array.from(child.children)
                .map(card => card.getAttribute(this.cardIdAttr))
                .filter(Boolean);
        });
        Object.entries(this.sizes).forEach(([id, span]) => {
            if (this.cards[id] && this.spanPresets.some(p => p.value === span)) {
                payload.sizes[id] = span;
            }
        });
        Object.entries(this.heights).forEach(([id, h]) => {
            if (this.cards[id] && Number.isFinite(h) && h > 0) {
                payload.heights[id] = h;
            }
        });

        try {
            localStorage.setItem(this.storageKey, JSON.stringify(payload));
        } catch (e) {}
    }

    parseSavedOrder() {
        try {
            const savedRaw = localStorage.getItem(this.storageKey);
            if (!savedRaw) return null;
            const saved = JSON.parse(savedRaw);
            if (saved && saved.version === 2) {
                return {
                    rowOrder: Array.isArray(saved._rowOrder) ? saved._rowOrder : [],
                    rows: saved.rows && typeof saved.rows === 'object' ? saved.rows : {},
                    sizes: saved.sizes && typeof saved.sizes === 'object' ? saved.sizes : {},
                    heights: saved.heights && typeof saved.heights === 'object' ? saved.heights : {},
                };
            }
            const rows = {};
            Object.entries(saved || {}).forEach(([key, value]) => {
                if (key !== '_rowOrder' && Array.isArray(value)) rows[key] = value;
            });
            return {
                rowOrder: Array.isArray(saved._rowOrder) ? saved._rowOrder : [],
                rows,
                sizes: {},
                heights: {},
            };
        } catch (e) {
            return null;
        }
    }

    restoreOrder() {
        const saved = this.parseSavedOrder();
        if (!saved) return;

        Object.entries(this.sizes).forEach(([id]) => delete this.sizes[id]);
        Object.entries(saved.sizes).forEach(([id, span]) => {
            if (this.cards[id]) this.sizes[id] = span;
        });
        Object.entries(saved.heights).forEach(([id, h]) => {
            if (this.cards[id]) this.heights[id] = h;
        });

        Object.entries(saved.rows).forEach(([rowId, ids]) => {
            if (!this.rows[rowId]) {
                const row = document.createElement('div');
                row.dataset.rowId = rowId;
                row.className = this.rowClass;
                this.rows[rowId] = row;

                ids.forEach(id => {
                    if (this.cards[id]) {
                        row.appendChild(this.cards[id]);
                    }
                });

                if (row.children.length) {
                    this.container.insertBefore(row, this.dropZone || null);
                }
            }
        });

        Object.entries(saved.rows).forEach(([rowId, ids]) => {
            const row = this.rows[rowId];
            if (!row) return;

            ids.forEach(id => {
                if (this.cards[id]) {
                    row.appendChild(this.cards[id]);
                }
            });
        });

        saved.rowOrder.forEach(rowId => {
            if (this.rows[rowId] && this.rows[rowId].children.length > 0) {
                this.container.insertBefore(this.rows[rowId], this.dropZone || null);
            }
        });
    }

    mergeNewCards() {
        const placed = new Set();
        Object.values(this.rows).forEach(row => {
            Array.from(row.children).forEach(card => {
                const id = card.getAttribute(this.cardIdAttr);
                if (id) placed.add(id);
            });
        });

        Object.keys(this.cards).forEach(id => {
            if (placed.has(id)) return;
            const rowId = this.defaultRowFor(id);
            let row = rowId ? this.rows[rowId] : null;
            if (!row) {
                row = document.createElement('div');
                row.dataset.rowId = rowId || ('dynamic-' + id);
                row.className = this.rowClass;
                this.rows[row.dataset.rowId] = row;
                this.container.insertBefore(row, this.dropZone || null);
                this.initRowSortable(row);
            }
            row.appendChild(this.cards[id]);
        });
    }

    setupResetButton() {
        if (this.container.querySelector('[data-grid-reset]')) return;

        const wrap = document.createElement('div');
        wrap.setAttribute('data-grid-reset-wrap', '');
        wrap.className = 'mt-6 pt-4 border-t border-gray-200 dark:border-gray-800 flex justify-end';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-grid-reset', '');
        btn.className = 'text-xs text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/80 hover:bg-gray-50 dark:hover:bg-gray-700/80 transition-colors shadow-2xs';
        btn.innerHTML = `
            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <span>${this.__('Reset Layout')}</span>
        `;

        btn.addEventListener('click', () => this.resetOrder());
        wrap.appendChild(btn);

        this.container.appendChild(wrap);
    }

    updateResetButtonVisibility() {
        const isCustom = !!localStorage.getItem(this.storageKey);
        const resetBtns = this.container.querySelectorAll('[data-grid-reset]');
        resetBtns.forEach(btn => {
            const wrap = btn.closest('[data-grid-reset-wrap]') || btn;
            // Always the last block of the grid: re-appending rows while a
            // layout change is applied could otherwise leave it on top.
            if (wrap.parentElement === this.container && this.container.lastElementChild !== wrap) {
                this.container.appendChild(wrap);
            }
            if (isCustom) {
                wrap.style.display = '';
                btn.classList.add('ring-1', 'ring-amber-500/30');
            } else {
                wrap.style.display = 'none';
                btn.classList.remove('ring-1', 'ring-amber-500/30');
            }
        });
    }

    resetOrder() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch (e) {}

        this.sortableInstances.forEach(inst => {
            try {
                inst.destroy();
            } catch (e) {}
        });
        this.sortableInstances.clear();

        this.rows = {};
        this.sizes = {};
        this.heights = {};
        this.dropZone = null;

        Array.from(this.container.children).forEach(child => {
            if (child.dataset && child.dataset.rowId) {
                child.remove();
            }
        });

        Object.values(this.cards).forEach(card => {
            card.style.removeProperty('--card-span');
            card.style.removeProperty('min-height');
            card.classList.remove('analytics-custom-height');
        });

        this.rowDefinitions.forEach(definition => {
            const row = document.createElement('div');
            row.dataset.rowId = definition.id;
            row.className = this.rowClass;
            this.rows[definition.id] = row;

            definition.cards.forEach(id => {
                if (this.cards[id]) {
                    row.appendChild(this.cards[id]);
                }
            });

            if (row.children.length > 0) {
                this.container.appendChild(row);
            }
        });

        this.mergeNewCards();
        this.applySizes();
        this.applyHeights();
        this.setupDropZones();
        this.initSortables();
        this.refreshAllRowLayouts();
        this.fitAllScrollAreas();

        this.updateResetButtonVisibility();
        this.refreshCharts();

        if (this.onOrderChange) {
            this.onOrderChange();
        }
    }
}
