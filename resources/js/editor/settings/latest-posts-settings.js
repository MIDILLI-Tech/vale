/**
 * Latest Posts feed settings dialog with dynamic category/tag selectors and dark mode.
 */
import { createModal } from '../ui/modal.js';

export function openLatestPostsSettings(editor, node, pos) {
  const nodeAttrs = node ? { ...node.attrs } : {};
  let initialContentType = nodeAttrs.contentType || 'post';
  if (initialContentType === '[object Object]' || (initialContentType && typeof initialContentType === 'object')) {
    initialContentType = 'post';
  }

  const m = createModal({ className: 'vl-modal-sm', structured: true });

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';

  const titleEl = document.createElement('h3');
  titleEl.className = 'vl-editor-modal-title dark:text-gray-100';
  titleEl.textContent = tjs('Latest Posts Settings');
  head.appendChild(titleEl);
  m.modal.appendChild(head);

  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  m.modal.appendChild(body);

  const bodyEl = document.createElement('div');
  bodyEl.className = 'vl-modal-flex-col space-y-4';
  body.appendChild(bodyEl);

  // Helper for creating labels
  function createLabel(text) {
    const lbl = document.createElement('label');
    lbl.className = 'vl-label block text-xs font-semibold text-gray-700 dark:text-gray-300';
    lbl.textContent = tjs(text);
    return lbl;
  }

  // 1. Post Count
  const countGroup = document.createElement('div');
  countGroup.className = 'flex flex-col gap-1';
  countGroup.appendChild(createLabel('Post Count'));
  const countInp = document.createElement('input');
  countInp.type = 'number';
  countInp.className = 'vl-input w-full dark:bg-gray-900 dark:text-gray-100 dark:border-gray-700';
  countInp.min = '1';
  countInp.max = '100';
  countInp.value = nodeAttrs.count || '5';
  countGroup.appendChild(countInp);
  bodyEl.appendChild(countGroup);

  // 2. Content Type
  const typeGroup = document.createElement('div');
  typeGroup.className = 'flex flex-col gap-1';
  typeGroup.appendChild(createLabel('Content Type'));
  const typeSelect = document.createElement('select');
  typeSelect.className = 'vl-select w-full dark:bg-gray-900 dark:text-gray-100 dark:border-gray-700';

  const typeOptions = [];
  if (window._VL_BLOG_ENABLED !== '0') {
    typeOptions.push({ value: 'post', label: tjs('Posts') });
  }
  const cpts = window._VL_ACTIVE_CPTS || [];
  cpts.forEach(ct => {
    const slugVal = (ct.slug && typeof ct.slug === 'object') ? ct.slug.value : ct.slug;
    typeOptions.push({ value: slugVal, label: ct.name });
  });

  typeOptions.forEach(opt => {
    const o = document.createElement('option');
    o.value = opt.value;
    o.textContent = opt.label;
    if (opt.value === initialContentType) {
      o.selected = true;
    }
    typeSelect.appendChild(o);
  });
  typeGroup.appendChild(typeSelect);
  bodyEl.appendChild(typeGroup);

  // 3. Category Slug
  const catGroup = document.createElement('div');
  catGroup.className = 'flex flex-col gap-1';
  catGroup.appendChild(createLabel('Category (optional)'));
  const catSelect = document.createElement('select');
  catSelect.className = 'vl-select w-full dark:bg-gray-900 dark:text-gray-100 dark:border-gray-700';
  catGroup.appendChild(catSelect);
  bodyEl.appendChild(catGroup);

  // 4. Tags (Multi-select with server-side search fallback)
  const tagGroup = document.createElement('div');
  tagGroup.className = 'flex flex-col gap-1 relative';
  tagGroup.appendChild(createLabel('Tags (optional)'));

  const tagChipsRow = document.createElement('div');
  tagChipsRow.className = 'flex flex-wrap gap-1.5 hidden';
  tagGroup.appendChild(tagChipsRow);

  const tagSearchInput = document.createElement('input');
  tagSearchInput.type = 'text';
  tagSearchInput.className = 'vl-input w-full dark:bg-gray-900 dark:text-gray-100 dark:border-gray-700';
  tagSearchInput.placeholder = tjs('Search or select tags...');

  tagGroup.appendChild(tagSearchInput);

  const tagBodyWrap = document.createElement('div');
  tagBodyWrap.className = 'relative';
  tagGroup.appendChild(tagBodyWrap);

  const tagDropdownList = document.createElement('div');
  tagDropdownList.className = 'absolute left-0 right-0 top-full z-30 mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg hidden';
  tagBodyWrap.appendChild(tagDropdownList);
  bodyEl.appendChild(tagGroup);

  // Taxonomy dynamic state
  let loadedTags = [];
  let serverSearchMode = false;
  let searchDebounce = null;
  let selectedCategorySlug = nodeAttrs.category || '';
  const selectedTagSlugs = [];
  const tagNameBySlug = new Map();
  {
    const initialTags = String(nodeAttrs.tag || '').split(',').map(s => s.trim()).filter(Boolean);
    selectedTagSlugs.push(...initialTags);
    initialTags.forEach(s => tagNameBySlug.set(s, s));
  }

  // API fetches
  async function fetchTaxonomies(contentType) {
    const lang = window._VL_CONTENT_LANG || '';
    const basePath = window.APP_BASE_PATH || '';

    const [categoriesRes, tagsRes] = await Promise.all([
      fetch(`${basePath}/admin/categories/list-json?content_type=${contentType}&language=${lang}`).then(r => r.json()).catch(() => []),
      fetch(`${basePath}/admin/tags/list-json?content_type=${contentType}&language=${lang}`).then(r => r.json()).catch(() => ({ data: [], total: 0, truncated: false }))
    ]);

    const tagsPayload = Array.isArray(tagsRes) ? { data: tagsRes, total: tagsRes.length, truncated: false } : tagsRes;
    return { categories: categoriesRes, tags: tagsPayload.data || [], tagsTruncated: !!tagsPayload.truncated };
  }

  function renderCategoryDropdown(categories) {
    catSelect.innerHTML = '';
    
    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = `— ${tjs('None')} —`;
    catSelect.appendChild(noneOpt);

    categories.forEach(cat => {
      const o = document.createElement('option');
      o.value = cat.slug;
      o.textContent = cat.name;
      if (cat.slug === selectedCategorySlug) {
        o.selected = true;
      }
      catSelect.appendChild(o);
    });
  }

  async function searchTagsServer(query) {
    const contentType = typeSelect.value;
    const lang = window._VL_CONTENT_LANG || '';
    const basePath = window.APP_BASE_PATH || '';
    const url = `${basePath}/admin/tags/list-json?content_type=${contentType}&language=${lang}&q=${encodeURIComponent(query)}`;
    const res = await fetch(url).then(r => r.json()).catch(() => ({ data: [], total: 0, truncated: false }));
    const payload = Array.isArray(res) ? { data: res } : res;
    (payload.data || []).forEach(t => tagNameBySlug.set(t.slug, t.name));
    return payload.data || [];
  }

  function renderTagChips() {
    tagChipsRow.innerHTML = '';
    if (!selectedTagSlugs.length) {
      tagChipsRow.classList.add('hidden');
      return;
    }
    tagChipsRow.classList.remove('hidden');
    selectedTagSlugs.forEach(slug => {
      const chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-100 text-xs font-medium px-2.5 py-1';
      const name = tagNameBySlug.get(slug) || slug;
      const text = document.createElement('span');
      text.textContent = name;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'text-gray-400 dark:text-gray-500 hover:text-red-500 font-bold focus:outline-none';
      remove.innerHTML = '&times;';
      remove.addEventListener('click', () => {
        const idx = selectedTagSlugs.indexOf(slug);
        if (idx !== -1) selectedTagSlugs.splice(idx, 1);
        renderTagChips();
      });
      chip.appendChild(text);
      chip.appendChild(remove);
      tagChipsRow.appendChild(chip);
    });
  }

  function renderTagItems(tags) {
    tagDropdownList.innerHTML = '';

    if (tags.length === 0) {
      const emptyOpt = document.createElement('div');
      emptyOpt.className = 'px-3 py-2 text-sm text-gray-400 dark:text-gray-500 italic';
      emptyOpt.textContent = tjs('No tags found');
      tagDropdownList.appendChild(emptyOpt);
    } else {
      tags.forEach(tag => {
        if (selectedTagSlugs.includes(tag.slug)) return;
        const item = document.createElement('div');
        item.className = 'px-3 py-2 text-sm text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer';
        item.textContent = tag.name;
        item.addEventListener('mousedown', (e) => {
          e.preventDefault();
          if (!selectedTagSlugs.includes(tag.slug)) {
            selectedTagSlugs.push(tag.slug);
            tagNameBySlug.set(tag.slug, tag.name);
          }
          tagSearchInput.value = '';
          renderTagChips();
          filterAndShowTags();
        });
        tagDropdownList.appendChild(item);
      });
    }

    tagDropdownList.classList.remove('hidden');
  }

  function filterAndShowTags() {
    const query = tagSearchInput.value.trim().toLowerCase();

    if (serverSearchMode) {
      renderTagItems(loadedTags);
      return;
    }

    const filtered = loadedTags.filter(t => {
      return t.name.toLowerCase().includes(query) || t.slug.toLowerCase().includes(query);
    });

    renderTagItems(filtered);
  }

  tagSearchInput.addEventListener('focus', () => {
    filterAndShowTags();
  });

  tagSearchInput.addEventListener('input', () => {
    if (!serverSearchMode) {
      filterAndShowTags();
      return;
    }
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(async () => {
      const query = tagSearchInput.value.trim();
      if (query === '') {
        tagDropdownList.classList.add('hidden');
        return;
      }
      loadedTags = await searchTagsServer(query);
      filterAndShowTags();
    }, 250);
  });

  tagSearchInput.addEventListener('blur', () => {
    setTimeout(() => {
      tagDropdownList.classList.add('hidden');
      tagSearchInput.value = '';
    }, 200);
  });

  async function onContentTypeChange() {
    const contentType = typeSelect.value;
    
    catSelect.innerHTML = `<option value="">${tjs('Loading categories...')}</option>`;
    tagSearchInput.placeholder = tjs('Loading tags...');
    tagSearchInput.disabled = true;

    const data = await fetchTaxonomies(contentType);

    renderCategoryDropdown(data.categories);
    loadedTags = data.tags;
    serverSearchMode = data.tagsTruncated;

    data.tags.forEach(t => tagNameBySlug.set(t.slug, t.name));
    if (selectedTagSlugs.length) {
      const known = selectedTagSlugs.filter(s => tagNameBySlug.has(s) && tagNameBySlug.get(s) !== s);
      const unknown = selectedTagSlugs.filter(s => !known.includes(s));
      if (unknown.length) {
        const lang = window._VL_CONTENT_LANG || '';
        const basePath = window.APP_BASE_PATH || '';
        fetch(`${basePath}/admin/tags/list-json?content_type=${contentType}&language=${lang}&slugs=${encodeURIComponent(unknown.join(','))}`)
          .then(r => r.json())
          .then(res => {
            (res.data || []).forEach(t => tagNameBySlug.set(t.slug, t.name));
            renderTagChips();
          })
          .catch(() => {});
      }
    }

    tagSearchInput.disabled = false;
    tagSearchInput.placeholder = serverSearchMode ? tjs('Type to search tags...') : tjs('Search or select tags...');
    renderTagChips();
  }

  typeSelect.addEventListener('change', onContentTypeChange);

  // 5. Actions row
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
    const countVal = countInp.value.trim() || '5';
    const typeVal = typeSelect.value;
    const catVal = catSelect.value || '';
    const tagVal = selectedTagSlugs.join(',');

    const current = editor.state.doc.nodeAt(pos);
    const base = current ? { ...current.attrs } : { ...nodeAttrs };
    
    editor.chain().focus().command(({ tr }) => {
      tr.setNodeMarkup(pos, undefined, {
        ...base,
        count: countVal,
        contentType: typeVal,
        category: catVal,
        tag: tagVal
      });
      return true;
    }).run();
    m.close();
  });

  actionsRow.appendChild(cancelBtn);
  actionsRow.appendChild(saveBtn);
  m.modal.appendChild(actionsRow);

  // Initial load
  onContentTypeChange();
}
