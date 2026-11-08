/**
 * Card grid settings dialog.
 *
 * Opens a modal with one tab per card (tab label = the card's title text).
 * Each tab edits that card's own attrs (image, link, target, background
 * override). Also exposes group-level gap/background/radius/alignment.
 * Edits are applied to the actual child nodes via setNodeMarkup at their
 * live positions.
 */
import { createModal } from '../ui/modal.js';
import { renderField, labelEl } from './schema-modal.js';
import { linkField } from './link-button-settings.js';
import { defaultCardContent } from '../embeds/card-item.js';
import { openMediaPickerExtended } from '../media/browse.js';

const TARGET_OPTIONS = [
  { v: '_self', l: tjs('Same window') },
  { v: '_blank', l: tjs('New tab') },
];

const RADIUS_OPTIONS = [
  { v: '0', l: tjs('Sharp (no rounding)') },
  { v: '4px', l: '4px' },
  { v: '8px', l: '8px' },
  { v: '12px', l: '12px' },
  { v: '16px', l: '16px' },
  { v: '24px', l: '24px' },
];

const ALIGN_OPTIONS = [
  { v: 'left', l: tjs('Align left') },
  { v: 'center', l: tjs('Center') },
  { v: 'right', l: tjs('Align right') },
];

function cardTitleText(child) {
  const title = child.node.firstChild;
  return ((title && title.textContent) || '').trim() || tjs('Card');
}

function resolveCards(editor, groupPos) {
  const node = editor.state.doc.nodeAt(groupPos);
  if (!node || node.type.name !== 'cardGroup') return [];
  const children = [];
  let childPos = groupPos + 1;
  node.forEach((child) => {
    children.push({ pos: childPos, node: child });
    childPos += child.nodeSize;
  });
  return children;
}

/**
 * Index (within the group at groupPos) of the card the caret is currently
 * editing, so the settings dialog opens on that card instead of always the
 * first one. Falls back to 0 when the selection isn't inside this group.
 */
export function activeCardIndex(editor, groupPos) {
  const sel = editor?.state?.selection;
  const $pos = sel && (sel.$anchor || sel.$from);
  if (!$pos) return 0;
  for (let d = $pos.depth; d > 0; d--) {
    const n = $pos.node(d);
    if (n && (n.type.name === 'cardTitle' || n.type.name === 'cardDesc')) {
      // Card item sits one level above the title/desc textblock.
      const item = d - 1 > 0 ? $pos.node(d - 1) : null;
      if (item && item.type.name === 'cardItem' && $pos.before(d - 2) === groupPos) {
        return $pos.index(d - 2);
      }
      return 0;
    }
    if (n && n.type.name === 'cardItem') {
      return $pos.before(d - 1) === groupPos ? $pos.index(d - 1) : 0;
    }
  }
  return 0;
}

export function openCardSettings(editor, groupPos, initialIndex = 0) {
  const m = createModal({ className: 'vl-modal vl-modal-lg', backdropGuardMs: 500, structured: true });

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';

  const h3 = document.createElement('h3');
  h3.className = 'vl-editor-modal-title';
  h3.textContent = tjs('Cards');
  head.appendChild(h3);
  m.modal.appendChild(head);

  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  m.modal.appendChild(body);

  const bodyWrap = document.createElement('div');
  bodyWrap.className = 'vl-modal-flex-col pr-1';
  bodyWrap.style.minHeight = '440px';
  body.appendChild(bodyWrap);

  let tabsEl = null;
  let panelsWrap = null;
  let panels = []; // { patch, readers, el }
  let active = Math.max(0, initialIndex | 0);

  function groupAttrs() {
    const node = editor.state.doc.nodeAt(groupPos);
    return node ? { ...node.attrs } : { gap: '12', bg: '', radius: '12px', align: 'center', halign: 'center', width: 'auto', lightbox: 'false' };
  }

  function setGroupAttr(key, value) {
    const g = editor.state.doc.nodeAt(groupPos);
    if (g) editor.chain().focus().command(({ tr }) => {
      tr.setNodeMarkup(groupPos, undefined, { ...g.attrs, [key]: value });
      return true;
    }).run();
  }

  function buildPanelViews(children) {
    panels = [];
    const panelsFragment = document.createDocumentFragment();

    children.forEach((child, index) => {
      const wrap = document.createElement('div');
      wrap.className = 'vl-group-btn-panel vl-modal-flex-col';
      wrap.style.display = index === active ? '' : 'none';

      const patch = { ...child.node.attrs };
      const ctx = {
        editor,
        pos: child.pos,
        node: child.node,
        modal: m.modal,
        close: () => m.close(),
        getValue: k => patch[k],
        setValue: (k, v) => { patch[k] = v; },
      };

      const readers = [];

      // Image — custom renderer with preview + library/URL pickers
      const imgLbl = labelEl(tjs('Media'));
      wrap.appendChild(imgLbl);
      const imgRow = document.createElement('div');
      imgRow.className = 'vl-modal-flex-row mb-2';
      const imgPrev = document.createElement('div');
      imgPrev.className = 'vl-card-settings-preview';
      const imgInput = document.createElement('input');
      imgInput.type = 'text';
      imgInput.className = 'vl-input flex-1';
      imgInput.placeholder = tjs('Media URL or pick from library…');
      imgInput.value = patch.image || '';
      const imgPick = document.createElement('button');
      imgPick.type = 'button';
      imgPick.className = 'vl-btn vl-btn-secondary';
      imgPick.textContent = '🖼';
      imgPick.title = tjs('Pick from media library');
      imgPick.addEventListener('click', () => {
        openMediaPickerExtended(editor, (url) => {
          imgInput.value = url || '';
          paintPreview();
        }, 'image/', imgInput.value.trim());
      });
      const imgClear = document.createElement('button');
      imgClear.type = 'button';
      imgClear.className = 'vl-btn vl-btn-secondary';
      imgClear.textContent = '✕';
      imgClear.title = tjs('Remove media');
      imgClear.addEventListener('click', () => {
        imgInput.value = '';
        paintPreview();
      });
      function isVideoUrl(u) { return /\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(u) || u.includes('youtube.com') || u.includes('youtu.be') || u.includes('vimeo.com'); }
      function paintPreview() {
        const u = imgInput.value.trim();
        if (!u) { imgPrev.innerHTML = `<span>${tjs('No media')}</span>`; return; }
        if (isVideoUrl(u) && !u.includes('youtube.com') && !u.includes('youtu.be') && !u.includes('vimeo.com')) {
          imgPrev.innerHTML = `<video src="${u.replace(/"/g, '&quot;')}" muted playsinline style="width:100%;height:auto;display:block;"></video>`;
        } else if (isVideoUrl(u)) {
          imgPrev.innerHTML = `<div style="aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;">🎬</div>`;
        } else {
          imgPrev.innerHTML = `<img src="${u.replace(/"/g, '&quot;')}" alt="">`;
        }
      }
      paintPreview();
      wrap.appendChild(imgPrev);
      imgRow.appendChild(imgInput);
      imgRow.appendChild(imgPick);
      imgRow.appendChild(imgClear);
      wrap.appendChild(imgRow);
      readers.push(() => { patch.image = imgInput.value.trim(); });

      const autoplayLbl = document.createElement('label');
      autoplayLbl.className = 'vl-modal-label-check mb-2';
      const autoplayInput = document.createElement('input');
      autoplayInput.type = 'checkbox';
      autoplayInput.checked = patch.autoplay === 'true';
      autoplayLbl.appendChild(autoplayInput);
      autoplayLbl.appendChild(document.createTextNode(' ' + tjs('Autoplay (video only)')));
      wrap.appendChild(autoplayLbl);
      readers.push(() => { patch.autoplay = autoplayInput.checked ? 'true' : 'false'; });

      const fitRendered = renderField(editor, { key: 'fit', type: 'select', label: tjs('Media Fit'), options: ['cover', 'contain'] }, patch.fit || 'cover', ctx);
      fitRendered.el.querySelector('select').addEventListener('change', event => { patch.fit = event.target.value; });
      wrap.appendChild(fitRendered.el);
      readers.push(() => { patch.fit = fitRendered.read(); });

      // Link — same External URL / Internal Content picker as link buttons.
      // linkField speaks 'href'; cards store it as 'link', so proxy the key.
      // It writes straight into patch via setValue: no reader needed.
      const linkCtx = {
        ...ctx,
        getValue: k => patch[k === 'href' ? 'link' : k],
        setValue: (k, v) => { patch[k === 'href' ? 'link' : k] = v; },
      };
      const linkRendered = linkField().render(linkCtx);
      linkRendered.classList.add('mb-2');
      wrap.appendChild(linkRendered);

      const targetRendered = renderField(editor, { key: 'target', type: 'select', label: tjs('Target'), options: TARGET_OPTIONS }, patch.target || '_self', ctx);
      wrap.appendChild(targetRendered.el);
      readers.push(() => { patch.target = targetRendered.read(); });

      const bgRendered = renderField(editor, { key: 'bg', type: 'color', label: tjs('Card Background (empty = group default)'), fallbackValue: '' }, patch.bg || '', ctx);
      wrap.appendChild(bgRendered.el);
      readers.push(() => { patch.bg = bgRendered.read(); });

      panels.push({ patch, readers, el: wrap });
      panelsFragment.appendChild(wrap);
    });

    return panelsFragment;
  }

  function render() {
    bodyWrap.innerHTML = '';

    // Group-level controls
    const groupControls = document.createElement('div');
    groupControls.className = 'mb-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg';
    const gAttrs = groupAttrs();

    const bgRendered = (function () {
      const tmp = document.createElement('div');
      const r = renderField(editor, { key: '__groupBg', type: 'color', label: tjs('Cards Background'), fallbackValue: '' }, gAttrs.bg || '', {
        getValue: () => gAttrs.bg || '',
        setValue: () => {},
      });
      tmp.appendChild(r.el);
      const input = tmp.querySelector('input');
      const apply = () => setGroupAttr('bg', input.value.trim());
      input.addEventListener('change', apply);
      tmp.querySelectorAll('button').forEach(b => b.addEventListener('click', () => setTimeout(apply, 0)));
      return tmp;
    })();
    groupControls.appendChild(bgRendered);

    const radiusLbl = labelEl(tjs('Corner Rounding'));
    radiusLbl.style.marginTop = '8px';
    groupControls.appendChild(radiusLbl);
    const radiusSel = document.createElement('select');
    radiusSel.className = 'vl-select w-full mb-2';
    RADIUS_OPTIONS.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.v; opt.textContent = tjs(o.l);
      if ((gAttrs.radius || '12px') === o.v) opt.selected = true;
      radiusSel.appendChild(opt);
    });
    radiusSel.addEventListener('change', () => setGroupAttr('radius', radiusSel.value));
    groupControls.appendChild(radiusSel);

    const alignLbl = labelEl(tjs('Text Alignment'));
    groupControls.appendChild(alignLbl);
    const alignSel = document.createElement('select');
    alignSel.className = 'vl-select w-full mb-2';
    ALIGN_OPTIONS.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.v; opt.textContent = tjs(o.l);
      if ((gAttrs.align || 'center') === o.v) opt.selected = true;
      alignSel.appendChild(opt);
    });
    alignSel.addEventListener('change', () => setGroupAttr('align', alignSel.value));
    groupControls.appendChild(alignSel);

    const halignLbl = labelEl(tjs('Group Alignment'));
    groupControls.appendChild(halignLbl);
    const halignSel = document.createElement('select');
    halignSel.className = 'vl-select w-full mb-2';
    ALIGN_OPTIONS.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.v; opt.textContent = tjs(o.l);
      if ((gAttrs.halign || 'center') === o.v) opt.selected = true;
      halignSel.appendChild(opt);
    });
    halignSel.addEventListener('change', () => setGroupAttr('halign', halignSel.value));
    groupControls.appendChild(halignSel);

    const gapLbl = labelEl(tjs('Gap between cards (px)'));
    groupControls.appendChild(gapLbl);
    const gapInput = document.createElement('input');
    gapInput.type = 'number';
    gapInput.min = 0;
    gapInput.className = 'vl-input w-full';
    gapInput.value = String(gAttrs.gap ?? '12');
    gapInput.addEventListener('change', () => setGroupAttr('gap', String(gapInput.value || '0')));
    groupControls.appendChild(gapInput);

    const widthLbl = labelEl(tjs('Group Width'));
    widthLbl.style.marginTop = '8px';
    groupControls.appendChild(widthLbl);
    const widthInput = document.createElement('input');
    widthInput.type = 'text';
    widthInput.className = 'vl-input w-full mb-2';
    widthInput.placeholder = tjs('e.g. 50%, 800px, auto');
    widthInput.value = gAttrs.width && gAttrs.width !== 'auto' ? gAttrs.width : '';
    widthInput.addEventListener('change', () => setGroupAttr('width', widthInput.value.trim() || 'auto'));
    groupControls.appendChild(widthInput);

    const lbLbl = document.createElement('label');
    lbLbl.className = 'vl-modal-label-check';
    const lbCb = document.createElement('input');
    lbCb.type = 'checkbox';
    lbCb.checked = (gAttrs.lightbox || 'false') === 'true';
    lbCb.addEventListener('change', () => setGroupAttr('lightbox', lbCb.checked ? 'true' : 'false'));
    lbLbl.appendChild(lbCb);
    lbLbl.appendChild(document.createTextNode(' ' + tjs('Lightbox (click to enlarge)')));
    groupControls.appendChild(lbLbl);
    bodyWrap.appendChild(groupControls);

    // Tab bar
    const children = resolveCards(editor, groupPos);
    if (active >= children.length) active = Math.max(0, children.length - 1);

    tabsEl = document.createElement('div');
    tabsEl.className = 'flex flex-wrap gap-1 mb-3 border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden p-1 items-center';
    tabsEl.style.flexShrink = '0';

    children.forEach((child, index) => {
      const tab = document.createElement('button');
      tab.type = 'button';
      tab.className = 'vl-editor-modal-tab' + (index === active ? ' is-active' : '');
      tab.textContent = cardTitleText(child);
      tab.title = tjs('Edit this card');
      tab.addEventListener('click', () => { active = index; renderBody(); });
      tabsEl.appendChild(tab);
    });

    // "+" add card tab
    const addTab = document.createElement('button');
    addTab.type = 'button';
    addTab.className = 'vl-editor-modal-tab';
    addTab.textContent = '+';
    addTab.title = tjs('Add card');
    addTab.addEventListener('click', () => {
      applyPanels();
      const g = editor.state.doc.nodeAt(groupPos);
      if (!g) return;
      const end = groupPos + g.nodeSize - 1;
      editor.chain().focus().insertContentAt(end, defaultCardContent()).run();
      active = resolveCards(editor, groupPos).length - 1;
      render();
    });
    tabsEl.appendChild(addTab);
    bodyWrap.appendChild(tabsEl);

    // Panels
    panelsWrap = document.createElement('div');
    panelsWrap.appendChild(buildPanelViews(children));
    bodyWrap.appendChild(panelsWrap);

    // Delete card (keeps UI simple: last card deletes the whole group)
    const delRow = document.createElement('div');
    delRow.className = 'flex mt-3';
    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'vl-btn vl-btn-danger text-xs';
    delBtn.textContent = tjs('Delete this card');
    delBtn.addEventListener('click', () => {
      applyPanels();
      const ch = resolveCards(editor, groupPos);
      const target = ch[active];
      if (!target) return;
      if (ch.length <= 1) {
        const g = editor.state.doc.nodeAt(groupPos);
        editor.chain().focus()
          .deleteRange({ from: groupPos, to: groupPos + (g ? g.nodeSize : 0) }).run();
        m.close();
        return;
      }
      editor.chain().focus()
        .deleteRange({ from: target.pos, to: target.pos + target.node.nodeSize }).run();
      if (active >= ch.length - 1) active = ch.length - 2;
      render();
    });
    delRow.appendChild(delBtn);
    bodyWrap.appendChild(delRow);
  }

  function renderBody() {
    if (!panelsWrap) return render();
    panels.forEach((pan, i) => {
      pan.el.style.display = i === active ? '' : 'none';
    });
    const tabBtns = [...tabsEl.querySelectorAll('.vl-editor-modal-tab')];
    tabBtns.forEach((t, ti) => t.classList.toggle('is-active', ti === active && ti < panels.length));
  }

  function applyPanels() {
    const children = resolveCards(editor, groupPos);
    const scrollY = window.scrollY;
    children.forEach((child) => {
      const pan = panels[children.indexOf(child)];
      if (!pan) return;
      pan.readers.forEach(r => r());
      const current = editor.state.doc.nodeAt(child.pos);
      const base = current ? { ...current.attrs } : { ...child.node.attrs };
      editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(child.pos, undefined, { ...base, ...pan.patch });
        return true;
      }).run();
    });
    editor.view.dom.focus({ preventScroll: true });
    requestAnimationFrame(() => window.scrollTo(0, scrollY));
  }

  render();

  const actionsRow = document.createElement('div');
  actionsRow.className = 'vl-editor-modal-foot';
  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.className = 'vl-btn vl-btn-secondary';
  cancelBtn.textContent = tjs('Cancel');
  cancelBtn.addEventListener('click', () => m.close());
  const saveBtn = document.createElement('button');
  saveBtn.type = 'button';
  saveBtn.className = 'vl-btn vl-btn-primary';
  saveBtn.textContent = tjs('Save');
  saveBtn.addEventListener('click', () => {
    applyPanels();
    m.close();
  });
  actionsRow.appendChild(cancelBtn);
  actionsRow.appendChild(saveBtn);
  m.modal.appendChild(actionsRow);
}
