/**
 * Panel / Card Reordering Module for Vale CMS Admin
 * Supports dragging via SortableJS and top/up/down/bottom arrow actions.
 * Drag handle positioned in card's left padding gutter; arrow actions top-right.
 * Persists layout per entity type in localStorage.
 */

export class PanelReorderManager {
    constructor() {
        this.instances = [];
    }

    init() {
        this.injectStyles();
        const reorderGroups = document.querySelectorAll('[data-panel-reorder-key]');
        reorderGroups.forEach(group => {
            const storageKey = group.getAttribute('data-panel-reorder-key');
            if (!storageKey) return;

            const instance = new PanelReorderInstance(group, storageKey);
            instance.setup();
            this.instances.push(instance);
        });
    }

    injectStyles() {
        if (document.getElementById('panel-reorder-styles')) return;
        const style = document.createElement('style');
        style.id = 'panel-reorder-styles';
        style.textContent = `
            @media (hover: none) {
                .panel-drag-handle,
                .panel-reorder-controls-right {
                    opacity: 1 !important;
                }
            }
            /* Rail cards use p-4, so the ⠿ handle sits right against the
               heading text. Nudge the heading in to open a gutter for it. */
            .panel-heading-gutter {
                padding-left: 0.25rem;
            }
        `;
        document.head.appendChild(style);
    }
}

class PanelReorderInstance {
    constructor(groupEl, key) {
        this.groupEl = groupEl;
        this.key = key;
        this.storageKey = 'vale_panel_order_' + key;
        this.containers = Array.from(groupEl.querySelectorAll('[data-panel-container]'));
        this.originalOrders = new Map();
    }

    __ (key) {
        return (window.VL_I18N && window.VL_I18N[key]) ? window.VL_I18N[key] : key;
    }

    setup() {
        if (this.containers.length === 0) return;

        // Record original DOM orders before any reordering
        this.containers.forEach(container => {
            const containerName = container.getAttribute('data-panel-container');
            const panels = Array.from(container.querySelectorAll('[data-panel-id]'));
            this.originalOrders.set(containerName, panels);
        });

        // Apply saved order from localStorage if present
        this.restoreLayout();

        // Inject UI controls into all panels and initialize Sortable
        this.containers.forEach(container => {
            const panels = Array.from(container.querySelectorAll('[data-panel-id]'));
            panels.forEach(panel => this.injectControls(panel, container));

            if (window.Sortable) {
                window.Sortable.create(container, {
                    handle: '.panel-drag-handle',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    delay: 120,
                    delayOnTouchOnly: true,
                    touchStartThreshold: 5,
                    onEnd: () => this.saveLayout()
                });
            }
        });

        // Setup reset button(s)
        this.setupResetButtons();
    }

    restoreLayout() {
        const raw = localStorage.getItem(this.storageKey);
        if (!raw) return;

        try {
            const saved = JSON.parse(raw);
            this.containers.forEach(container => {
                const containerName = container.getAttribute('data-panel-container');
                const savedIds = saved[containerName];
                if (!Array.isArray(savedIds)) return;

                const panelMap = new Map();
                const existingPanels = Array.from(container.querySelectorAll('[data-panel-id]'));
                existingPanels.forEach(p => panelMap.set(p.getAttribute('data-panel-id'), p));

                // Append matching panels in saved order
                savedIds.forEach(id => {
                    const panel = panelMap.get(id);
                    if (panel) {
                        container.appendChild(panel);
                        panelMap.delete(id);
                    }
                });

                // Append any remaining panels not in saved list
                panelMap.forEach(panel => container.appendChild(panel));
            });
        } catch (e) {
            console.error('Failed to parse saved layout for ' + this.key, e);
        }
    }

    saveLayout() {
        const state = {};
        this.containers.forEach(container => {
            const containerName = container.getAttribute('data-panel-container');
            const panels = Array.from(container.querySelectorAll('[data-panel-id]'));
            state[containerName] = panels.map(p => p.getAttribute('data-panel-id'));
        });

        localStorage.setItem(this.storageKey, JSON.stringify(state));
        this.updateResetButtonVisibility();
    }

    resetLayout() {
        localStorage.removeItem(this.storageKey);
        this.containers.forEach(container => {
            const containerName = container.getAttribute('data-panel-container');
            const originalPanels = this.originalOrders.get(containerName);
            if (originalPanels) {
                originalPanels.forEach(panel => container.appendChild(panel));
            }
        });
        this.updateResetButtonVisibility();
    }

    injectControls(panel, container) {
        if (panel.querySelector('.panel-drag-handle')) return;

        // Ensure parent panel has group and relative classes for hover transitions
        panel.classList.add('group', 'relative');

        // 1. Drag Handle: Positioned in card's left padding gutter, vertically centered on the title text
        const dragHandle = document.createElement('span');
        dragHandle.className = 'panel-drag-handle absolute left-0.5 sm:left-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity cursor-move text-gray-400 hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-200 select-none font-bold text-base leading-none z-10 p-0.5 flex items-center';
        dragHandle.title = this.__('Drag to reorder');
        dragHandle.innerHTML = '⠿';

        const heading = panel.querySelector('h1, h2, h3, h4');
        // Only the rail (right/sidebar) column needs the extra gutter: its
        // cards use p-4, while the main column's p-6 already clears the handle.
        const containerName = container.getAttribute('data-panel-container');
        if (heading && (containerName === 'right' || containerName === 'sidebar')) {
            heading.classList.add('panel-heading-gutter');
        }
        panel.appendChild(dragHandle);

        const alignHandle = () => {
            if (heading && heading.offsetHeight > 0 && dragHandle.offsetHeight > 0) {
                const top = heading.offsetTop + ((heading.offsetHeight - dragHandle.offsetHeight) / 2);
                dragHandle.style.top = Math.max(0, top) + 'px';
            } else if (heading && heading.offsetTop > 0) {
                dragHandle.style.top = heading.offsetTop + 'px';
            } else {
                dragHandle.style.top = '22px';
            }
        };
        alignHandle();
        requestAnimationFrame(alignHandle);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(alignHandle).catch(() => {});
        }

        // 2. Action Arrows: Positioned top-right
        const arrowEl = document.createElement('div');
        arrowEl.className = 'panel-reorder-controls-right absolute top-3 right-3 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity flex items-center gap-0.5 bg-white/90 dark:bg-gray-800/90 border border-gray-200/80 dark:border-gray-700/80 rounded-md p-0.5 shadow-2xs z-10 select-none';
        arrowEl.innerHTML = `
            <button type="button" data-panel-action="top" title="${this.__('Move to top')}" class="p-1 rounded text-gray-400 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 leading-none text-xs transition-colors">⇈</button>
            <button type="button" data-panel-action="up" title="${this.__('Move up')}" class="p-1 rounded text-gray-400 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 leading-none text-xs transition-colors">↑</button>
            <button type="button" data-panel-action="down" title="${this.__('Move down')}" class="p-1 rounded text-gray-400 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 leading-none text-xs transition-colors">↓</button>
            <button type="button" data-panel-action="bottom" title="${this.__('Move to bottom')}" class="p-1 rounded text-gray-400 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 leading-none text-xs transition-colors">⇊</button>
        `;

        panel.appendChild(arrowEl);

        // Attach action handlers
        arrowEl.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-panel-action]');
            if (!btn) return;

            const action = btn.getAttribute('data-panel-action');
            if (action === 'top') {
                container.prepend(panel);
            } else if (action === 'up') {
                let prev = panel.previousElementSibling;
                while (prev && !prev.hasAttribute('data-panel-id')) {
                    prev = prev.previousElementSibling;
                }
                if (prev && prev.hasAttribute('data-panel-id')) {
                    prev.before(panel);
                }
            } else if (action === 'down') {
                let next = panel.nextElementSibling;
                while (next && !next.hasAttribute('data-panel-id')) {
                    next = next.nextElementSibling;
                }
                if (next && next.hasAttribute('data-panel-id')) {
                    next.after(panel);
                }
            } else if (action === 'bottom') {
                container.append(panel);
            }

            this.saveLayout();
        });
    }

    setupResetButtons() {
        const resetBtns = this.groupEl.querySelectorAll('[data-panel-reset]');
        resetBtns.forEach(btn => {
            btn.addEventListener('click', () => this.resetLayout());
        });

        // Automatically create a reset layout button if none exists and a right/sidebar container is present
        if (resetBtns.length === 0) {
            const rightContainer = this.groupEl.querySelector('[data-panel-container="right"]') ||
                                   this.groupEl.querySelector('[data-panel-container="sidebar"]') ||
                                   this.containers[this.containers.length - 1];

            if (rightContainer) {
                const wrap = document.createElement('div');
                wrap.setAttribute('data-panel-reset-wrap', '');
                wrap.className = 'mt-6 pt-4 border-t border-gray-200 dark:border-gray-800 flex justify-end';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.setAttribute('data-panel-reset', '');
                btn.className = 'text-xs text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/80 hover:bg-gray-50 dark:hover:bg-gray-700/80 transition-colors shadow-2xs';
                btn.innerHTML = `
                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>${this.__('Reset Layout')}</span>
                `;

                btn.addEventListener('click', () => this.resetLayout());
                wrap.appendChild(btn);

                if (rightContainer.parentElement) {
                    rightContainer.parentElement.appendChild(wrap);
                } else {
                    this.groupEl.appendChild(wrap);
                }
            }
        }

        this.updateResetButtonVisibility();
    }

    updateResetButtonVisibility() {
        const isCustom = !!localStorage.getItem(this.storageKey);
        const resetBtns = this.groupEl.querySelectorAll('[data-panel-reset]');
        resetBtns.forEach(btn => {
            const wrap = btn.closest('[data-panel-reset-wrap]') || btn;
            if (isCustom) {
                wrap.style.display = '';
                btn.classList.add('ring-1', 'ring-amber-500/30');
            } else {
                wrap.style.display = 'none';
                btn.classList.remove('ring-1', 'ring-amber-500/30');
            }
        });
    }
}

export function initPanelReorder() {
    const manager = new PanelReorderManager();
    manager.init();
    window.panelReorderManager = manager;
}
