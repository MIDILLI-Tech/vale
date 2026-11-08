/**
 * Declarative block settings dialog.
 *
 * A block declares its settings as a field schema; this module renders the
 * dialog, wires pickers/color inputs and persists values via setNodeMarkup.
 *
 * Field types:
 *   text / number / select / checkbox / color / page / divider / custom
 *
 * Example:
 *   openBlockSettings(editor, pos, {
 *     title: 'Newsletter Settings',
 *     fields: [
 *       { key: 'placeholder', type: 'text', label: 'Placeholder' },
 *       { key: 'privacyPage', type: 'page', label: 'Privacy Page' },
 *       { key: 'bg', type: 'color', label: 'Background' },
 *     ],
 *   });
 */
import { createModal } from '../ui/modal.js';
import { colorSwatchTitle, openColorPicker } from '../ui/color-picker.js';
import { openPagePicker } from '../ui/page-picker.js';
import { getCsrfToken } from '../utils.js';

function labelEl(text) {
  const l = document.createElement('label');
  l.className = 'vl-label';
  l.textContent = tjs(text);
  return l;
}

function makeSelect(f, value) {
  const sel = document.createElement('select');
  sel.className = 'vl-select w-full';
  const options = f.options.map(o => (typeof o === 'string' ? { v: o, l: o } : o));
  for (const o of options) {
    const opt = document.createElement('option');
    opt.value = o.v;
    opt.textContent = typeof o.l === 'string' ? tjs(o.l) : o.l;
    if (String(value ?? '') === String(o.v)) opt.selected = true;
    sel.appendChild(opt);
  }
  return sel;
}

/**
 * Pill-style tag editor with live search suggestions (vanilla JS equivalent
 * of the `tagInput()` Alpine component used by the post editor).
 *
 * Suggestions come from `f.suggestions` (static array) or `f.suggestionsUrl`
 * (fetched once when the dialog opens; if the server reports `truncated`,
 * further queries go to the server debounced). `read()` returns a
 * comma-separated string so it round-trips through shortcode attributes.
 */
const tagsSuggestionCache = new Map();

function makeTagsField(f, rawValue) {
  const wrap = document.createElement('div');
  const items = String(rawValue ?? '')
    .split(',')
    .map(v => v.trim())
    .filter(v => v !== '');
  let suggestions = Array.isArray(f.suggestions) ? [...f.suggestions] : [];
  let serverTruncated = false;
  let loadingStarted = false;
  let searchTimer = null;
  let loading = false;

  const mergeSuggestions = (names) => {
    for (const name of names) {
      if (typeof name === 'string' && name !== '' && suggestions.indexOf(name) === -1) {
        suggestions.push(name);
      }
    }
  };

  const fetchSuggestions = (query) => {
    if (!f.suggestionsUrl) return Promise.resolve();
    let url = f.suggestionsUrl;
    if (query) url += (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(query);
    if (tagsSuggestionCache.has(url)) {
      const cached = tagsSuggestionCache.get(url);
      mergeSuggestions(cached.names);
      if (!query) serverTruncated = cached.truncated;
      renderDropdown();
      return Promise.resolve();
    }
    loading = true;
    renderDropdown();
    return fetch(url, { headers: { 'X-CSRF-Token': getCsrfToken() } })
      .then(r => r.json())
      .then(payload => {
        const data = Array.isArray(payload) ? payload : (payload.data || []);
        const names = data.map(t => (typeof t === 'string' ? t : t.name)).filter(Boolean);
        tagsSuggestionCache.set(url, { names, truncated: !!payload.truncated });
        mergeSuggestions(names);
        if (!query) serverTruncated = !!payload.truncated;
        renderDropdown();
      })
      .catch(() => {})
      .finally(() => {
        loading = false;
        renderDropdown();
      });
  };

  const ensureLoaded = () => {
    if (loadingStarted || !f.suggestionsUrl) return;
    loadingStarted = true;
    fetchSuggestions('');
  };

  const pills = document.createElement('div');
  pills.className = 'flex flex-wrap gap-2 mb-2';

  const box = document.createElement('div');
  box.className = 'relative';

  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'vl-input w-full';
  input.placeholder = f.placeholder ? tjs(f.placeholder) : tjs('Type tag name...');

  const dropdown = document.createElement('div');
  dropdown.className = 'absolute z-10 mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg max-h-48 overflow-y-auto';
  dropdown.style.display = 'none';

  let focusedIndex = -1;

  const filtered = () => {
    const q = input.value.trim().toLowerCase();
    // Empty query lists everything preloaded, so clicking the field alone
    // offers all tags for point-and-click selection.
    return suggestions.filter(tag => (q === '' || tag.toLowerCase().indexOf(q) !== -1) && items.indexOf(tag) === -1);
  };

  const renderPills = () => {
    pills.replaceChildren();
    pills.style.display = items.length > 0 ? '' : 'none';
    for (const item of items) {
      const pill = document.createElement('span');
      pill.className = 'inline-flex items-center gap-1 rounded-full bg-gray-100 dark:bg-gray-800 px-2.5 py-1 text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700';
      const label = document.createElement('span');
      label.textContent = item;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400';
      remove.textContent = '×';
      remove.setAttribute('aria-label', tjs('Remove'));
      remove.addEventListener('click', () => {
        const i = items.indexOf(item);
        if (i !== -1) items.splice(i, 1);
        renderPills();
        renderDropdown();
      });
      pill.appendChild(label);
      pill.appendChild(remove);
      pills.appendChild(pill);
    }
  };

  const renderDropdown = () => {
    const list = filtered();
    dropdown.replaceChildren();
    focusedIndex = -1;
    if (list.length === 0) {
      if (loading) {
        const row = document.createElement('div');
        row.className = 'px-3 py-2 text-sm text-gray-500 dark:text-gray-400';
        row.textContent = tjs('Loading...');
        dropdown.appendChild(row);
        dropdown.style.display = '';
      } else {
        dropdown.style.display = 'none';
      }
      return;
    }
    list.forEach((tag, i) => {
      const row = document.createElement('div');
      row.className = 'cursor-pointer px-3 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800';
      row.textContent = tag;
      row.addEventListener('mouseenter', () => highlight(i));
      row.addEventListener('mousedown', (e) => {
        e.preventDefault();
        addTag(tag);
      });
      dropdown.appendChild(row);
    });
    dropdown.style.display = '';
  };

  const highlight = (i) => {
    focusedIndex = i;
    [...dropdown.children].forEach((row, j) => {
      row.classList.toggle('bg-gray-100', j === i);
      row.classList.toggle('dark:bg-gray-800', j === i);
    });
  };

  const addTag = (tag) => {
    const clean = String(tag).trim().replace(/,+$/, '');
    if (clean === '' || items.indexOf(clean) !== -1) return;
    items.push(clean);
    input.value = '';
    renderPills();
    renderDropdown();
    input.focus();
  };

  const addFromInput = () => {
    const list = filtered();
    if (focusedIndex >= 0 && focusedIndex < list.length) {
      addTag(list[focusedIndex]);
    } else {
      addTag(input.value);
    }
  };

  input.addEventListener('input', () => {
    renderDropdown();
    // When the preloaded list was truncated server-side, keep searching
    // the server as the user types (debounced).
    if (serverTruncated && f.suggestionsUrl) {
      clearTimeout(searchTimer);
      const query = input.value.trim();
      if (query !== '') {
        searchTimer = setTimeout(() => { fetchSuggestions(query); }, 250);
      }
    }
  });
  input.addEventListener('focus', () => {
    ensureLoaded();
    renderDropdown();
  });
  input.addEventListener('blur', () => {
    setTimeout(() => { dropdown.style.display = 'none'; }, 200);
  });
  input.addEventListener('keydown', (e) => {
    const list = filtered();
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addFromInput();
    } else if (e.key === 'Backspace' && input.value === '' && items.length > 0) {
      items.pop();
      renderPills();
      renderDropdown();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (focusedIndex < list.length - 1) highlight(focusedIndex + 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (focusedIndex > -1) highlight(focusedIndex - 1);
    } else if (e.key === 'Escape') {
      dropdown.style.display = 'none';
      focusedIndex = -1;
    }
  });

  box.appendChild(input);
  box.appendChild(dropdown);
  if (f.suggestionsUrl) {
    const row = document.createElement('div');
    row.className = 'flex gap-1';
    row.appendChild(input);
    const refreshBtn = document.createElement('button');
    refreshBtn.type = 'button';
    refreshBtn.className = 'vl-btn-icon shrink-0';
    refreshBtn.title = tjs('Refresh');
    refreshBtn.innerHTML = '<span class="vl-icon-refresh"></span>';
    refreshBtn.addEventListener('click', () => {
      for (const key of [...tagsSuggestionCache.keys()]) {
        if (key === f.suggestionsUrl || key.indexOf(f.suggestionsUrl + '?') === 0 || key.indexOf(f.suggestionsUrl + '&') === 0) {
          tagsSuggestionCache.delete(key);
        }
      }
      serverTruncated = false;
      loadingStarted = false;
      input.focus();
      ensureLoaded();
      renderDropdown();
    });
    row.appendChild(refreshBtn);
    box.appendChild(row);
  } else {
    box.appendChild(input);
  }
  box.appendChild(dropdown);
  wrap.appendChild(pills);
  wrap.appendChild(box);

  renderPills();

  return {
    el: wrap,
    read: () => items.join(','),
  };
}

function wireColorField(row, input, pickerDefault) {
  const btn = row.querySelector('.vl-color-btn');
  const clearBtn = row.querySelector('[data-vl-clear]');
  btn.addEventListener('click', e => {
    e.stopPropagation();
    openColorPicker({
      anchor: btn,
      currentColor: input.value || pickerDefault,
      onPreview(hex) { input.value = hex; btn.style.background = hex; },
      onSelect(hex) {
        input.value = hex;
        btn.style.background = hex;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      },
      onRemove() {
        input.value = 'transparent';
        btn.style.background = 'transparent';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      },
    });
  });
  clearBtn.addEventListener('click', () => {
    input.value = 'transparent';
    btn.style.background = 'transparent';
  });
}

function renderField(editor, f, rawValue, ctx) {
  const wrap = document.createElement('div');

  if (f.type === 'text') {
    wrap.appendChild(labelEl(f.label));
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'vl-input w-full';
    input.value = rawValue ?? '';
    if (f.placeholder) input.placeholder = tjs(f.placeholder);
    wrap.appendChild(input);
    if (f.help) {
      const help = document.createElement('p');
      help.className = 'vl-modal-subtext';
      help.textContent = f.help;
      wrap.appendChild(help);
    }
    return {
      el: wrap,
      read: () => {
        let v = input.value.trim();
        if (f.parse) v = f.parse(v);
        return v;
      },
    };
  }

  if (f.type === 'number') {
    wrap.appendChild(labelEl(f.label));
    const row = document.createElement('div');
    if (f.suffixSelect) row.className = 'vl-modal-flex-row';
    const input = document.createElement('input');
    input.type = 'number';
    input.className = 'vl-input flex-1';
    input.value = rawValue ?? '';
    if (f.min != null) input.min = f.min;
    if (f.max != null) input.max = f.max;
    if (f.step != null) input.step = f.step;
    row.appendChild(input);
    if (f.suffixSelect) {
      const sel = makeSelect({ options: f.suffixSelect.options }, ctx.getValue(f.suffixSelect.key));
      sel.className = 'vl-select w-[80px]';
      sel.addEventListener('change', () => ctx.setValue(f.suffixSelect.key, sel.value));
      row.appendChild(sel);
    }
    wrap.appendChild(row);
    return {
      el: wrap,
      read: () => {
        let v = input.value.trim();
        if (f.parse) v = f.parse(v);
        return v;
      },
    };
  }

  if (f.type === 'select') {
    wrap.appendChild(labelEl(f.label));
    wrap.appendChild(makeSelect(f, rawValue));
    return {
      el: wrap,
      read: () => wrap.querySelector('select').value,
    };
  }

  if (f.type === 'tags') {
    wrap.appendChild(labelEl(f.label));
    const tags = makeTagsField(f, rawValue);
    wrap.appendChild(tags.el);
    if (f.help) {
      const help = document.createElement('p');
      help.className = 'vl-modal-subtext';
      help.textContent = tjs(f.help);
      wrap.appendChild(help);
    }
    return {
      el: wrap,
      read: () => tags.read(),
    };
  }

  if (f.type === 'checkbox') {
    wrap.className = 'mb-2';
    const lbl = document.createElement('label');
    lbl.className = 'vl-modal-label-check';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.checked = !!rawValue;
    lbl.appendChild(cb);
    lbl.appendChild(document.createTextNode(' ' + tjs(f.label)));
    wrap.appendChild(lbl);
    return { el: wrap, read: () => cb.checked };
  }

  if (f.type === 'color') {
    wrap.appendChild(labelEl(f.label));
    const row = document.createElement('div');
    row.className = 'vl-modal-flex-row';
    const shown = rawValue || f.fallbackValue || '';
    const placeholderText = f.placeholder ? tjs(f.placeholder) : tjs('hex or transparent');
    row.innerHTML = `
      <button type="button" class="vl-color-btn shrink-0" title="${colorSwatchTitle(tjs(f.label))}" style="background:${shown || f.btnFallback || 'var(--vl-toolbar-btn-hover)'}"> </button>
      <input type="text" class="vl-input flex-1" value="${shown}" placeholder="${placeholderText}">
      <button type="button" data-vl-clear class="vl-btn vl-btn-secondary text-xs py-1 px-2">${tjs('Clear')}</button>`;
    wrap.appendChild(row);
    wireColorField(row, row.querySelector('input'), f.pickerDefault || '#000000');
    return {
      el: wrap,
      read: () => row.querySelector('input').value.trim(),
    };
  }

  if (f.type === 'page') {
    wrap.appendChild(labelEl(f.label));
    const row = document.createElement('div');
    row.className = 'flex gap-1';
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'vl-input w-full';
    input.value = rawValue ?? '';
    if (f.placeholder) input.placeholder = tjs(f.placeholder);
    const pick = document.createElement('button');
    pick.type = 'button';
    pick.className = 'vl-btn vl-btn-secondary';
    pick.textContent = '🔍';
    pick.addEventListener('click', () => {
      openPagePicker(editor, input.value, slug => { input.value = slug; }, tjs(f.label));
    });
    row.appendChild(input);
    row.appendChild(pick);
    wrap.appendChild(row);
    return { el: wrap, read: () => input.value.trim() };
  }

  throw new Error(`Unknown settings field type: ${f.type}`);
}

/**
 * Opens a schema-driven settings dialog for the node at `pos`.
 * Only declared (or explicitly set via ctx.setValue) keys are written back —
 * concurrent attribute changes (e.g. "refresh from server") are preserved.
 *
 * @param {Editor} editor
 * @param {number} pos
 * @param {{title: string, size?: string, scrollBody?: boolean, backdropGuardMs?: number,
 *          fields?: Array<Object>, actionsLeft?: Function, onSave?: Function, values?: Object,
 *          onInput?: Function, onReady?: Function}} opts
 */
export function openBlockSettings(editor, pos, opts) {
  const { title, size = 'vl-modal-sm', scrollBody = false, backdropGuardMs = 0, fields = [], actionsLeft = null, onSave = null, values = {}, onInput = null, onReady = null } = opts;

  const m = createModal({ className: size, backdropGuardMs, structured: true });
  const nodeAtOpen = editor.state.doc.nodeAt(pos);
  const nodeAttrs = nodeAtOpen ? { ...nodeAtOpen.attrs } : {};

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';

  const h3 = document.createElement('h3');
  h3.className = 'vl-editor-modal-title';
  h3.textContent = tjs(title);
  head.appendChild(h3);
  m.modal.appendChild(head);

  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  m.modal.appendChild(body);

  const bodyWrap = document.createElement('div');
  bodyWrap.className = 'vl-modal-flex-col' + (scrollBody ? ' max-h-[450px] overflow-y-auto pr-1' : '');
  body.appendChild(bodyWrap);

  const readers = [];
  const patch = {};
  const conditionals = [];
  const ctx = {
    editor,
    pos,
    node: nodeAtOpen,
    modal: m.modal,
    close: () => m.close(),
    getValue: key => (key in patch ? patch[key] : nodeAttrs[key]),
    setValue: (key, value) => { patch[key] = value; },
  };

  for (const f of fields) {
    if (f.type === 'divider') {
      bodyWrap.insertAdjacentHTML('beforeend', '<hr class="border-t border-gray-200 dark:border-gray-700 my-1">');
      continue;
    }
    if (f.type === 'custom') {
      bodyWrap.appendChild(f.render(ctx));
      continue;
    }
    const { el, read } = renderField(editor, f, values[f.key] ?? nodeAttrs[f.key], ctx);
    readers.push(() => { patch[f.key] = read(); });
    el.style.marginBottom = '10px';
    bodyWrap.appendChild(el);
    // Opt-in conditional visibility: { ..., showWhen: { key: 'captcha', value: 'honeypot' } }
    // shows the field only while another attribute equals the given value.
    if (f.showWhen) {
      const test = () => {
        const current = (f.showWhen.key in patch ? patch[f.showWhen.key] : undefined)
          ?? nodeAttrs[f.showWhen.key] ?? values[f.showWhen.key];
        el.style.display = String(current ?? '') === String(f.showWhen.value) ? '' : 'none';
      };
      conditionals.push(test);
      test();
    }
  }

  // Re-evaluate conditional fields on every change (selects fire `change`,
  // text inputs fire `input`). Reads run first so `patch` is current.
  const refreshConditionals = () => {
    if (conditionals.length === 0) return;
    readers.forEach(r => r());
    conditionals.forEach(t => t());
  };
  bodyWrap.addEventListener('input', refreshConditionals, { passive: true });
  bodyWrap.addEventListener('change', refreshConditionals);

  // Wire onInput callback for live preview
  if (onInput) {
    const inputs = bodyWrap.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
      input.addEventListener('input', () => {
        // Sync current input values into patch before calling onInput
        readers.forEach(r => r());
        onInput(patch, ctx);
      }, { passive: true });
    });
  }

  const actionsRow = document.createElement('div');
  actionsRow.className = 'vl-editor-modal-foot';

  const rightGroup = actionsRow;
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
    if (onSave) {
      const result = onSave(readers, patch, nodeAttrs, pos);
      if (result === false) return;
    } else {
      readers.forEach(r => r());
      const current = editor.state.doc.nodeAt(pos);
      const base = current ? { ...current.attrs } : { ...nodeAttrs };
      const scrollY = window.scrollY;
      editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(pos, undefined, { ...base, ...patch });
        return true;
      }).run();
      editor.view.dom.focus({ preventScroll: true });
      requestAnimationFrame(() => window.scrollTo(0, scrollY));
    }
    m.close();
  });

  if (actionsLeft) {
    actionsRow.style.display = 'flex';
    actionsRow.style.justifyContent = 'space-between';
    const leftGroup = document.createElement('div');
    leftGroup.style.cssText = 'display:flex;gap:8px';
    leftGroup.appendChild(actionsLeft(ctx));
    actionsRow.appendChild(leftGroup);

    const rightWrap = document.createElement('div');
    rightWrap.style.cssText = 'display:flex;gap:8px';
    rightWrap.appendChild(cancelBtn);
    rightWrap.appendChild(saveBtn);
    actionsRow.appendChild(rightWrap);
  } else {
    rightGroup.appendChild(cancelBtn);
    rightGroup.appendChild(saveBtn);
  }

  m.modal.appendChild(actionsRow);

  // Call onReady callback after modal is fully constructed
  if (onReady) {
    onReady(ctx);
  }
}

export { renderField, labelEl, makeSelect, wireColorField };
