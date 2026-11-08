/**
 * Media library browsers.
 *
 * Single implementation behind the four browse dialogs:
 *  - openMediaBrowser            → insert image/video straight into the editor
 *  - openMediaPickerExtended     → generic single pick via callback (+ external media)
 *  - pickMedia                   → image-only single pick via callback
 *  - openMediaBrowserForCarousel → multi select, applied on demand
 */
import { createModal } from "../ui/modal.js";
import { bp, getCsrfToken, embedBaseUrl } from "../utils.js";

const _editorMediaCache = new Map();

export function getEditorContentLanguage() {
  const sel = document.getElementById("language");
  if (sel && sel.value) {
    return sel.value;
  }
  if (typeof window._VL_CONTENT_LANG === "string" && window._VL_CONTENT_LANG) {
    return window._VL_CONTENT_LANG;
  }
  return "";
}
window.getEditorContentLanguage = getEditorContentLanguage;

export function withMediaBrowseLanguage(url) {
  const lang = getEditorContentLanguage();
  if (!lang) {
    return url;
  }
  const sep = url.includes("?") ? "&" : "?";
  return `${url}${sep}lang=${encodeURIComponent(lang)}`;
}

export async function _editorMediaFetch(url) {
  const resolvedUrl = withMediaBrowseLanguage(url);
  if (_editorMediaCache.has(resolvedUrl))
    return _editorMediaCache.get(resolvedUrl);
  const res = await fetch(resolvedUrl, {
    headers: { "X-CSRF-Token": getCsrfToken() },
  });
  const data = await res.json();
  _editorMediaCache.set(resolvedUrl, data);
  return data;
}

/**
 * Client-side media store: small libraries (per type, up to
 * window.CLIENT_SEARCH_FETCH_LIMIT) are preloaded once and search/paging are
 * served from memory; larger libraries are declined after the first response
 * (total > limit) and fall back to per-request browse-json calls. Mirrors the
 * admin media page's client-side search limit.
 */
const _clientMediaStore = {
  _entries: new Map(),
  _key(type, lang) {
    return `${type || ""}|${lang || ""}`;
  },
  _entry(type, lang) {
    const key = this._key(type, lang);
    if (!this._entries.has(key)) {
      this._entries.set(key, { all: null, promise: null, declined: false });
    }
    return this._entries.get(key);
  },
  reset() {
    this._entries.clear();
  },
  _matches(item, needle) {
    const hay = [item.name, item.alt_text, item.description, item.title]
      .filter((v) => typeof v === "string" && v !== "")
      .join(" ")
      .toLowerCase();
    return hay.includes(needle);
  },
  async _preload(type, lang, entry) {
    // _editorMediaFetch appends lang= automatically (withMediaBrowseLanguage).
    const first = await _editorMediaFetch(browseUrl(type, 1, ""));
    const limit = Number(
      window.CLIENT_SEARCH_FETCH_LIMIT ||
        window.VL_CLIENT_SEARCH_FETCH_LIMIT ||
        0,
    );
    if (!(limit > 0) || Number(first.total || 0) > limit) {
      entry.declined = true;
      return null;
    }
    const items = first.items || [];
    const totalPages = Number(first.total_pages || 1);
    for (let p = 2; p <= totalPages; p++) {
      const next = await _editorMediaFetch(browseUrl(type, p, ""));
      items.push(...(next.items || []));
    }
    entry.all = items;
    return items;
  },
  async all(type, lang) {
    const entry = this._entry(type, lang);
    if (entry.all) return entry.all;
    if (entry.declined) return null;
    if (!entry.promise) {
      entry.promise = this._preload(type, lang, entry).catch((err) => {
        if (window.APP_DEBUG) console.error("[editor media client store]", err);
        entry.declined = true;
        return null;
      });
    }
    return entry.promise;
  },
  async page(type, page = 1, query = "", perPage = 40, lang = "") {
    const items = await this.all(type, lang);
    if (items === null) return null;
    const needle = (query || "").trim().toLowerCase();
    const filtered = needle
      ? items.filter((i) => this._matches(i, needle))
      : items;
    const start = (page - 1) * perPage;
    return {
      items: filtered.slice(start, start + perPage),
      total: filtered.length,
      page,
      per_page: perPage,
      total_pages: Math.max(1, Math.ceil(filtered.length / perPage)),
    };
  },
};

export function clearEditorMediaClientStore() {
  _clientMediaStore.reset();
}

const TYPE_OPTIONS_FULL = [
  { value: "image/", label: "Images" },
  { value: "video/", label: "Videos" },
  { value: "application/,audio/,text/", label: "Files" },
  { value: "", label: "All Types" },
];

const TYPE_OPTIONS_IMAGES = [
  { value: "image/", label: "Images" },
  { value: "video/", label: "Videos" },
  { value: "", label: "All Types" },
];

/* Last used type filter for the full media browser (Images/Videos/Files/All
   Types), remembered across openings and page reloads. Restricted pickers
   (fixed mimeFilter) never read or write this. */
const MEDIA_TYPE_STORAGE_KEY = "vl_media_browser_type";

function readStoredMediaType(validValues) {
  try {
    const saved = localStorage.getItem(MEDIA_TYPE_STORAGE_KEY);
    // null = never stored (fall back to the default option below); "" is a
    // valid stored value ("All Types") and must be restored as-is.
    if (saved === null) return null;
    return validValues.includes(saved) ? saved : null;
  } catch {
    return null; // private mode / storage disabled
  }
}

function storeMediaType(type) {
  try {
    localStorage.setItem(MEDIA_TYPE_STORAGE_KEY, type);
  } catch {
    /* storage disabled — in-memory behavior is unaffected */
  }
}

// Subtitle picker: WebVTT only (SRT/SUB are converted to .vtt at ingest, so
// the SubRip detection is listed for legacy records).
const TYPE_OPTIONS_SUBTITLES = [
  { value: "text/vtt,application/x-subrip", label: "Subtitles" },
];

function browseUrl(type, page, q) {
  return bp(
    `/admin/media/browse-json?type=${encodeURIComponent(type)}&page=${page}&per_page=40${q ? "&q=" + encodeURIComponent(q) : ""}`,
  );
}

function loadingHtml() {
  return `<p class="vl-empty flex items-center justify-center gap-2 min-h-[200px]"><span class="vl-icon-refresh vl-icon-spin w-5 h-5"></span> ${tjs("Loading...")}</p>`;
}

function isVideoItem(item) {
  return !!(item.mime && item.mime.startsWith("video/"));
}

function isAudioItem(item) {
  return !!(item.mime && item.mime.startsWith("audio/"));
}

// Anything that is not an image is a "file" for pick/insert purposes
// (video/audio are handled earlier by their own branches): PDFs, Office
// documents, archives, subtitles (text/vtt), TXT — all go through the
// file-link dialog instead of the image branch.
function isFileItem(item) {
  if (!item.mime) return false;
  return !item.mime.startsWith("image/");
}

// WebVTT (and the pre-conversion SubRip detection) — used by the subtitle
// picker's type filter and by the multi-select guard below.
function isSubtitleItem(item) {
  if (!item.mime) return false;
  return item.mime === "text/vtt" || item.mime === "application/x-subrip";
}

/* Type-colored document/archive icons — keeps the picker usable in dark
   mode, where the old flat emoji were nearly invisible. Colors mirror the
   admin media library partial (admin/partials/_media_preview.twig). */
const DOC_ICON_PATH =
  '<path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6"/>';
const ARCHIVE_ICON_PATH =
  '<path stroke-linecap="round" stroke-linejoin="round" d="M21 8v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8M1 3h22v5H1zM10 12h4"/>';

const PLAY_BADGE_SVG =
  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>';
const MUSIC_BADGE_SVG =
  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6z"/></svg>';

function fileIconFor(item) {
  const mime = item.mime || "";
  const name = (item.name || "").toLowerCase();
  const ext = name.includes(".") ? name.split(".").pop() : "";
  const isArchive =
    mime === "application/zip" ||
    mime === "application/x-zip-compressed" ||
    ["zip", "rar", "7z", "gz", "tar"].includes(ext);
  const color =
    ext === "pdf" || mime === "application/pdf"
      ? "text-red-500"
      : isArchive
        ? "text-amber-500"
        : ["doc", "docx", "odt", "rtf", "txt", "pages"].includes(ext)
          ? "text-blue-500"
          : ["xls", "xlsx", "ods", "csv"].includes(ext)
            ? "text-green-500"
            : ["ppt", "pptx", "odp", "key"].includes(ext)
              ? "text-orange-500"
              : ["mp3", "wav", "ogg", "m4a", "flac", "aac"].includes(ext) ||
                  mime.startsWith("audio/")
                ? "text-violet-500"
                : "text-gray-400";
  return `<svg class="vl-file-icon ${color}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">${
    isArchive ? ARCHIVE_ICON_PATH : DOC_ICON_PATH
  }</svg>`;
}

/* Video/audio facade tiles (play badge, in-view poster, click-to-load
   player) are handled by the shared `assets/js/media-facade.js` — the
   markup above matches its contract. */

function formatFileSize(bytes) {
  const size = Number(bytes);
  if (!size) return "";
  if (size >= 1048576) return Math.round(size / 1048576) + " MB";
  return Math.max(1, Math.round(size / 1024)) + " KB";
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function cardHtml(item) {
  let thumb;
  if (isVideoItem(item) || isAudioItem(item)) {
    const badge = isAudioItem(item) ? MUSIC_BADGE_SVG : PLAY_BADGE_SVG;
    const label = escapeHtml(tjs("Preview"));
    thumb = `<div class="vl-media-facade" data-vl-media-facade data-vl-media-src="${escapeHtml(item.url)}" data-vl-media-mime="${escapeHtml(item.mime)}"><button type="button" class="vl-media-facade-play" title="${label}" aria-label="${label}">${badge}</button></div>`;
  } else if (isFileItem(item) || isSubtitleItem(item)) {
    thumb = `<div class="vl-media-file-tile">${fileIconFor(item)}</div>`;
  } else {
    thumb = `<img src="${item.thumbnail || item.url}" alt="${item.alt_text || item.name}" title="${item.description || item.name}">`;
  }
  return thumb + `<div class="vl-media-card-label" title="${item.name}">${item.name}</div>`;
}

function clearCachedPages(mime) {
  if (!mime) {
    _editorMediaCache.clear();
    return;
  }
  _editorMediaCache.forEach((_, k) => {
    if (k.includes(encodeURIComponent(mime))) _editorMediaCache.delete(k);
  });
}

/**
 * Core paginated browser dialog.
 *
 * mode 'single': clicking a card closes the browser and calls onPickItem(item).
 * mode 'multi':  cards toggle selection; footer Apply button calls onApply(urls).
 */
function openBrowser({
  titleKey,
  mimeFilter = "",
  typeOptions = TYPE_OPTIONS_FULL,
  selectClass = "",
  mode = "single",
  useCache = true,
  showExternal = false,
  externalButtonText = null,
  initialUrls = [],
  highlightUrl = "",
  onPickItem = null,
  onApply = null,
  onExternalPick = null,
  errorTextKey = tjs("Failed to load media."),
}) {
  const m = createModal({});

  const header = document.createElement("div");
  header.className = "vl-modal-header";
  header.innerHTML = `
    <h3 class="vl-modal-title">${tjs(titleKey)}</h3>
    ${
      showExternal
        ? `<div class="flex-1 flex gap-2 ml-4">
      <button type="button" class="vl-ext-btn vl-btn vl-btn-sm vl-btn-secondary">🔗 ${tjs("External Media")}</button>
    </div>`
        : ""
    }
    ${
      typeOptions
        ? `<select class="vl-select ${selectClass}">
      ${typeOptions.map((o) => `<option value="${o.value}">${tjs(o.label)}</option>`).join("")}
    </select>`
        : ""
    }
    <input type="search" class="vl-search" placeholder="${tjs("Search...")}">
    <button type="button" class="vl-btn-icon" title="${tjs("Refresh")}"><span class="vl-icon-refresh"></span></button>
    <button type="button" class="vl-btn-icon">✕</button>`;

  const typeSelect = header.querySelector("select");
  const searchInput = header.querySelector("input[type=search]");
  const refreshIcon = header.querySelector(".vl-icon-refresh");
  const closeBtn =
    header.querySelector(".vl-btn-icon:not([title])") ||
    header.querySelector("button:last-child");
  closeBtn.addEventListener("click", () => m.close());

  const grid = document.createElement("div");
  grid.className = "vl-media-grid";

  const body = document.createElement("div");
  body.className = "vl-modal-body";
  body.appendChild(grid);

  const footer = document.createElement("div");
  footer.className = "vl-modal-footer";

  m.modal.appendChild(header);
  m.modal.appendChild(body);
  m.modal.appendChild(footer);

  let currentPage = 1;
  let totalPages = 1;
  let currentMime = mimeFilter;
  // Restricted pickers (Images/Videos/All Types) also remember the last
  // used type filter, so reopening doesn't always start at Images. Subtitle
  // and carousel pickers keep their fixed filter.
  // Remembered type wins only when there is no concrete media to highlight;
  // otherwise the type of the currently selected media takes priority so the
  // picker opens on the right tab (image vs video).
  if (!highlightUrl && (typeOptions === TYPE_OPTIONS_FULL || typeOptions === TYPE_OPTIONS_IMAGES)) {
    const stored = readStoredMediaType(typeOptions.map((o) => o.value));
    if (stored !== null && typeOptions.map((o) => o.value).includes(stored)) {
      currentMime = stored;
    }
  }
  // Nothing remembered (or a fixed-filter picker without a filter): fall
  // back to the first option so the select and the fetch always agree —
  // an empty value here would load "All Types" behind an "Images" label.
  if (!currentMime && Array.isArray(typeOptions) && typeOptions.length > 0) {
    currentMime = typeOptions[0].value;
  }
  // Generation counter: overlapping loadPage() calls (type change, search,
  // pager, refresh) must not overwrite each other — only the latest request
  // may render. Same token pattern as the external-media probes below.
  let loadSeq = 0;

  // ── multi-select state & footer ───────────────────────────────────────────
  let selectedUrls = Array.isArray(initialUrls) ? [...initialUrls] : [];
  let selectedCount = null;
  let applyBtn = null;
  let pagerInfo = null;
  let prevBtn = null;
  let nextBtn = null;

  function updateSelectionUi() {
    if (selectedCount) {
      selectedCount.textContent = selectedUrls.length + " " + tjs("selected");
    }
    if (applyBtn) {
      applyBtn.disabled = selectedUrls.length === 0;
    }
  }

  if (mode === "multi") {
    footer.className = "vl-modal-footer flex flex-wrap items-center justify-between gap-2.5 p-4 border-t dark:border-gray-700";

    selectedCount = document.createElement("span");
    selectedCount.className = "text-sm font-medium";
    selectedCount.textContent = selectedUrls.length + " " + tjs("selected");

    pagerInfo = document.createElement("span");
    pagerInfo.className = "vl-media-pager-info text-xs text-gray-500 dark:text-gray-400";

    const leftGroup = document.createElement("div");
    leftGroup.className = "flex items-center gap-2.5";
    leftGroup.appendChild(selectedCount);
    leftGroup.appendChild(pagerInfo);

    const pagerWrap = document.createElement("div");
    pagerWrap.className = "vl-carousel-pager flex items-center gap-1.5";
    prevBtn = document.createElement("button");
    prevBtn.textContent = tjs("Previous");
    prevBtn.className = "vl-btn vl-btn-sm vl-btn-secondary";
    prevBtn.disabled = true;
    prevBtn.addEventListener("click", () => {
      currentPage--;
      loadPage();
    });
    nextBtn = document.createElement("button");
    nextBtn.textContent = tjs("Next");
    nextBtn.className = "vl-btn vl-btn-sm vl-btn-secondary";
    nextBtn.disabled = true;
    nextBtn.addEventListener("click", () => {
      currentPage++;
      loadPage();
    });
    pagerWrap.appendChild(prevBtn);
    pagerWrap.appendChild(nextBtn);

    const rightGroup = document.createElement("div");
    rightGroup.className = "flex items-center gap-2 w-full sm:w-auto justify-end pt-2 sm:pt-0 border-t sm:border-t-0 border-gray-200 dark:border-gray-700";

    const clearBtn = document.createElement("button");
    clearBtn.type = "button";
    clearBtn.className = "vl-btn vl-btn-secondary";
    clearBtn.textContent = tjs("Clear");
    clearBtn.addEventListener("click", () => {
      selectedUrls = [];
      updateSelectionUi();
      grid
        .querySelectorAll(".vl-media-card.is-selected")
        .forEach((c) => c.classList.remove("is-selected"));
    });

    applyBtn = document.createElement("button");
    applyBtn.type = "button";
    applyBtn.className = "vl-btn vl-btn-primary";
    applyBtn.textContent = tjs("Insert Selected");
    applyBtn.disabled = selectedUrls.length === 0;
    applyBtn.addEventListener("click", () => {
      onApply(selectedUrls);
      m.close();
    });

    rightGroup.appendChild(clearBtn);
    rightGroup.appendChild(applyBtn);
    footer.appendChild(leftGroup);
    footer.appendChild(pagerWrap);
    footer.appendChild(rightGroup);
  }

  // ── external media ────────────────────────────────────────────────────────
  if (showExternal) {
    header.querySelector(".vl-ext-btn").addEventListener("click", () => {
      openExternalMediaDialog({
        buttonText: externalButtonText ? tjs(externalButtonText) : undefined,
        onSelect: (url) => {
          if (mode === "multi") {
            selectedUrls.push(url);
            updateSelectionUi();
          } else {
            if (onExternalPick) onExternalPick(url);
            m.close();
          }
        },
      });
    });
  }

  function getItemUrls(item) {
    const urls = [];
    if (item.url) urls.push(item.url);
    if (item.thumbnails && typeof item.thumbnails === "object") {
      Object.values(item.thumbnails).forEach((u) => {
        if (typeof u === "string" && u) urls.push(u);
      });
    }
    if (item.thumbnail && !urls.includes(item.thumbnail)) {
      urls.push(item.thumbnail);
    }
    return urls;
  }

  function findSelectedUrlIndex(item) {
    const itemUrls = getItemUrls(item);
    return selectedUrls.findIndex((selected) => {
      if (!selected) return false;
      return itemUrls.some((u) => {
        if (u === selected) return true;
        try {
          const p1 = new URL(u, window.location.origin).pathname;
          const p2 = new URL(selected, window.location.origin).pathname;
          return p1 === p2;
        } catch {
          return false;
        }
      });
    });
  }

  // ── grid rendering ────────────────────────────────────────────────────────
  function renderGrid(items) {
    grid.innerHTML = "";
    if (!items.length) {
      grid.innerHTML = `<p class="vl-empty">${tjs("No files found.")}</p>`;
      return;
    }
    items.forEach((item) => {
      // mkv (video/x-matroska) is not playable by browser <video>, so
      // hide it from editor media pickers to avoid unplayable embeds.
      if (item.mime === 'video/x-matroska') return;
      if (
        mode === "multi" &&
        !isVideoItem(item) &&
        !isSubtitleItem(item) &&
        !(item.mime && item.mime.startsWith("image/"))
      )
        return;
      const card = document.createElement("div");
      card._item = item;
      const webpUrl = item.thumbnails?.webp || item.url;
      const isSelected = mode === "multi" && findSelectedUrlIndex(item) !== -1;
      // Highlight only when there is a single unambiguous match, so a
      // shared basename (e.g. video + its thumbnail) can't highlight the
      // wrong card.
      let isHighlighted = false;
      if (highlightUrl && item.url) {
        if (item.url === highlightUrl) {
          isHighlighted = true;
        } else {
          const base = highlightUrl.split("/").pop();
          const sameBase = items.filter(
            (it) => it.url && it.url.split("/").pop() === base,
          );
          if (sameBase.length === 1 && sameBase[0] === item) {
            isHighlighted = true;
          }
        }
      }
      card.className =
        "vl-media-card" +
        (isSelected || isHighlighted ? " is-selected" : "");
      card.innerHTML =
        cardHtml(item) +
        (mode === "multi" ? '<div class="vl-media-card-check">✓</div>' : "");
      card.addEventListener(
        "click",
        (ev) => {
          const clicked = (ev && ev.currentTarget && ev.currentTarget._item) || item;
          if (mode === "multi") {
            const foundIdx = findSelectedUrlIndex(clicked);
            if (foundIdx !== -1) {
              selectedUrls.splice(foundIdx, 1);
              card.classList.remove("is-selected");
            } else {
              selectedUrls.push(webpUrl);
              card.classList.add("is-selected");
            }
            updateSelectionUi();
          } else {
            m.close();
            onPickItem(clicked);
          }
        },
        mode === "single" ? { once: true } : undefined,
      );
      grid.appendChild(card);
    });
  }

  // ── pagination ────────────────────────────────────────────────────────────
  function renderPager() {
    if (mode === "multi") {
      pagerInfo.textContent =
        tjs("Page") + " " + currentPage + " / " + totalPages;
      prevBtn.disabled = currentPage <= 1;
      nextBtn.disabled = currentPage >= totalPages;
      return;
    }
    footer.innerHTML = "";
    const info = document.createElement("span");
    info.textContent =
      totalPages > 1
        ? tjs("Page :current of :total", {
            ":current": currentPage,
            ":total": totalPages,
          })
        : "";
    const prev = document.createElement("button");
    prev.type = "button";
    prev.textContent = "← " + tjs("Prev");
    prev.className = "vl-btn vl-btn-sm vl-btn-secondary";
    prev.disabled = currentPage <= 1;
    if (prev.disabled) prev.style.opacity = "0.4";
    const next = document.createElement("button");
    next.type = "button";
    next.textContent = tjs("Next") + " →";
    next.className = "vl-btn vl-btn-sm vl-btn-secondary";
    next.disabled = currentPage >= totalPages;
    if (next.disabled) next.style.opacity = "0.4";
    prev.addEventListener("click", () => {
      if (currentPage > 1) {
        currentPage--;
        loadPage();
      }
    });
    next.addEventListener("click", () => {
      if (currentPage < totalPages) {
        currentPage++;
        loadPage();
      }
    });
    footer.appendChild(prev);
    footer.appendChild(info);
    footer.appendChild(next);
  }

  function loadPage() {
    if (refreshIcon) refreshIcon.classList.add("vl-icon-spin");
    grid.innerHTML = loadingHtml();
    const seq = ++loadSeq;
    const isCurrent = () => seq === loadSeq;
    const q = searchInput.value.trim();
    const type = typeSelect ? currentMime : "";
    const lang = getEditorContentLanguage();
    const request = (async () => {
      if (useCache) {
        const local = await _clientMediaStore.page(
          type,
          currentPage,
          q,
          40,
          lang,
        );
        if (local) return local;
      }
      return _editorMediaFetch(browseUrl(type, currentPage, q));
    })();
    return request
      .then((data) => {
        if (!isCurrent()) return;
        totalPages = data.total_pages || 1;
        renderGrid(data.items || []);
        if (highlightUrl) {
          requestAnimationFrame(() => {
            const el = grid.querySelector(".vl-media-card.is-selected");
            if (el) el.scrollIntoView({ block: "center" });
          });
        }
        renderPager();
      })
      .catch((err) => {
        if (!isCurrent()) return;
        if (window.APP_DEBUG) console.error("[editor loadPage error]", err);
        grid.innerHTML = `<p class="vl-empty text-red-500">${errorTextKey}</p>`;
      })
      .finally(() => {
        if (isCurrent() && refreshIcon)
          refreshIcon.classList.remove("vl-icon-spin");
      });
  }

  if (typeSelect) {
    typeSelect.addEventListener("change", () => {
      currentMime = typeSelect.value;
      currentPage = 1;
      if (typeOptions === TYPE_OPTIONS_FULL || typeOptions === TYPE_OPTIONS_IMAGES) storeMediaType(currentMime);
      loadPage();
    });
    // Restored/default filter goes back into the select. "" is a valid
    // option value ("All Types"), so match against the option list instead
    // of truthiness — otherwise the select lies about what is loaded.
    if (typeOptions.some((o) => o.value === currentMime)) {
      typeSelect.value = currentMime;
    }
  }

  let searchTimer = null;
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      currentPage = 1;
      loadPage();
    }, 300);
  });

  header
    .querySelector('.vl-btn-icon[title="' + tjs("Refresh") + '"]')
    .addEventListener("click", () => {
      if (useCache) {
        clearCachedPages(currentMime);
        _clientMediaStore.reset();
      }
      currentPage = 1;
      loadPage();
    });

  // Drag-drop upload (assets/js/media-upload.js): files dropped on the
  // modal upload, the grid reloads from page 1 and the uploaded rows are
  // auto-selected — inserted immediately in single mode, added to the
  // selection in multi mode. The helper shows an explicit permission
  // error when the user lacks media.upload (server enforces it too).
  if (window.vlMediaUpload) {
    window.vlMediaUpload.dropZone(m.modal, {
      filter: (f) => window.vlMediaUpload.matchesFilter(f, currentMime),
      onDone: async (result) => {
        const uploaded = (result && result.uploaded) || [];
        if (mode === "multi") {
          uploaded.forEach((item) => {
            if (!selectedUrls.includes(item.url)) selectedUrls.push(item.url);
          });
          updateSelectionUi();
        }
        if (useCache) {
          clearCachedPages(currentMime);
          _clientMediaStore.reset();
        }
        currentPage = 1;
        await loadPage();
        if (mode !== "multi" && uploaded[0]) {
          m.close();
          onPickItem(uploaded[0]);
        }
      },
    });
  }

  loadPage();
}

/**
 * Browse the media library and insert the picked image/video into the editor.
 */
export function openMediaBrowser(editor, mimeFilter = "") {
  openBrowser({
    titleKey: "Media Library",
    mimeFilter,
    useCache: true,
    onPickItem(item) {
      if (isVideoItem(item)) {
        editor
          .chain()
          .focus()
          .insertContent({
            type: "videoEmbed",
            attrs: {
              html: `<video controls class="max-w-full"><source src="${item.url}" type="${item.mime}"></video>`,
            },
          })
          .run();
      } else if (isFileItem(item)) {
        openFileLinkDialog(editor, item);
      } else {
        const webpSrc = item.thumbnails?.webp || null;
        editor
          .chain()
          .focus()
          .setImage({
            src: webpSrc || item.url,
            alt: item.alt_text || item.name,
            _thumbnailSize: "full",
            _thumbnails: item.thumbnails || null,
          })
          .run();
      }
    },
  });
}

/**
 * Ask for the link text, then insert the picked file as a plain text link.
 * Shared by the media library file pick and the direct file upload flow.
 */
export function openFileLinkDialog(editor, item) {
  const m = createModal({ className: "vl-modal-md", structured: true });
  const size = formatFileSize(item.size);
  m.modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">${tjs("Insert File Link")}</h3>
    </div>
    <div class="vl-editor-modal-body">
      <p class="vl-editor-modal-sub">${fileIconFor(item)} ${escapeHtml(item.name)}${size ? ` <span class="text-gray-400">(${size})</span>` : ""}</p>
      <label class="vl-label">${tjs("Link text")}</label>
      <input type="text" class="vl-input vl-file-link-text" value="${escapeHtml(item.name)}">
      <label class="vl-link-blank-label flex items-center gap-1.5 text-xs mt-3 cursor-pointer">
        <input type="checkbox" class="vl-file-link-blank" checked> ${tjs("Open in new tab")}
      </label>
    </div>
    <div class="vl-editor-modal-foot">
      <button type="button" class="vl-btn vl-btn-secondary" data-vl-cancel>${tjs("Cancel")}</button>
      <button type="button" class="vl-btn vl-btn-primary" data-vl-insert>${tjs("Insert")}</button>
    </div>`;

  const textInput = m.modal.querySelector(".vl-file-link-text");
  const blankInput = m.modal.querySelector(".vl-file-link-blank");
  m.modal.querySelector("[data-vl-cancel]").addEventListener("click", () => m.close());
  m.modal.querySelector("[data-vl-insert]").addEventListener("click", () => {
    const text = textInput.value.trim() || item.name;
    editor
      .chain()
      .focus()
      .deleteSelection()
      .insertContent({
        type: "text",
        text,
        marks: [
          {
            type: "link",
            attrs: { href: item.url, target: blankInput.checked ? "_blank" : null },
          },
        ],
      })
      .run();
    m.close();
  });
  requestAnimationFrame(() => textInput.focus());
}

/**
 * Generic picker: returns (url, name) of any picked library item.
 */
export function openMediaPickerExtended(editor, onPick, mimeFilter = "", initialUrl = "") {
  let filter = mimeFilter;
  if (initialUrl) {
    if (/\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(initialUrl)) {
      filter = "video/";
    } else {
      filter = "image/";
    }
  }
  // External URLs can't be highlighted in the library.
  const highlightUrl = initialUrl && !/^https?:\/\//i.test(initialUrl) ? initialUrl : "";
  openBrowser({
    titleKey: "Select Media",
    mimeFilter: filter,
    typeOptions: TYPE_OPTIONS_IMAGES,
    selectClass: "w-[120px]",
    showExternal: true,
    highlightUrl,
    onPickItem: (item) => onPick(item.thumbnails?.webp || item.url, item.name),
    onExternalPick: (url) => onPick(url, ""),
  });
}

/**
 * Multi-select browser for carousel blocks.
 */
export function openMediaBrowserForCarousel(
  editor,
  onSelect,
  initialUrls = [],
) {
  openBrowser({
    titleKey: "Select Media",
    mimeFilter: "",
    typeOptions: null,
    mode: "multi",
    useCache: true,
    showExternal: true,
    externalButtonText: tjs("Add to Carousel"),
    errorTextKey: tjs("Failed to load images."),
    initialUrls,
    onApply: (urls) => onSelect(urls),
  });
}

/**
 * Multi-select browser for subtitle (.vtt) tracks.
 *
 * Cards come pre-selected for tracks already attached (matched by URL via
 * findSelectedUrlIndex), so reopening the dialog shows the current state.
 */
export function openSubtitleBrowser(editor, onApply, initialUrls = []) {
  openBrowser({
    titleKey: "Select Subtitles",
    mimeFilter: "text/vtt,application/x-subrip",
    typeOptions: TYPE_OPTIONS_SUBTITLES,
    selectClass: "w-[140px]",
    mode: "multi",
    useCache: true,
    showExternal: false,
    errorTextKey: tjs("Failed to load subtitles."),
    initialUrls,
    onApply: (urls) => onApply(urls),
  });
}

/**
 * External media (URL / YouTube / Vimeo / direct video) dialog.
 */
export function openExternalMediaDialog({ onSelect, buttonText, initialUrl, dialogTitle }) {
  const bt = buttonText || tjs("Select Media");
  const m = createModal({ className: "vl-modal-md p-6", zIndex: 10002 });
  m.modal.innerHTML = `
    <h3 class="vl-editor-modal-title mb-4">${dialogTitle || tjs("External Media")}</h3>
    <div class="mb-4">
      <label class="vl-label">${tjs("Media URL")}</label>
      <input type="url" id="ext-url" class="vl-input w-full" placeholder="${tjs("Image, YouTube, Vimeo, or direct video link")}">
    </div>
    <div id="ext-preview" class="vl-ext-preview w-full aspect-video vl-bg-soft rounded-lg flex items-center justify-center mb-5 overflow-hidden border border-gray-200 dark:border-gray-700">
      <span class="text-xs text-gray-500 dark:text-gray-400">${tjs("Preview will appear here")}</span>
    </div>
    <div class="vl-modal-actions-right">
      <button type="button" class="vl-btn vl-btn-secondary" data-vl-cancel>${tjs("Cancel")}</button>
      <button type="button" class="vl-btn vl-btn-primary" data-vl-add disabled>${bt}</button>
    </div>`;

  const input = m.modal.querySelector("#ext-url");
  const preview = m.modal.querySelector("#ext-preview");
  const addBtn = m.modal.querySelector("[data-vl-add]");
  const cancelBtn = m.modal.querySelector("[data-vl-cancel]");

  cancelBtn.addEventListener("click", () => m.close());

  let previewTimer = null;
  let probeToken = 0;
  input.addEventListener("input", () => {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(updatePreview, 500);
  });

  function showPreviewError(url) {
    // Ignore stale probe results — the user may have edited the URL meanwhile.
    if (input.value.trim() !== url) return;
    preview.innerHTML = `<span class="text-red-500 dark:text-red-400 text-xs text-center p-5 flex flex-col items-center gap-1"><span class="text-lg">⚠</span>${tjs("Media could not be loaded. Check the URL.")}</span>`;
    addBtn.disabled = true;
  }

  function probeDirectImage(url) {
    const probe = new Image();
    const token = ++probeToken;
    probe.onload = () => {
      if (token === probeToken && input.value.trim() === url)
        addBtn.disabled = false;
    };
    probe.onerror = () => {
      if (token === probeToken) showPreviewError(url);
    };
    probe.src = url;
  }

  function probeDirectVideo(url) {
    const probe = document.createElement("video");
    probe.preload = "metadata";
    const token = ++probeToken;
    probe.onloadedmetadata = () => {
      if (token === probeToken && input.value.trim() === url)
        addBtn.disabled = false;
    };
    probe.onerror = () => {
      if (token === probeToken) showPreviewError(url);
    };
    probe.src = url;
  }

  function probeOEmbed(endpoint, url) {
    const token = ++probeToken;
    fetch(endpoint)
      .then((r) => {
        if (token !== probeToken) return;
        if (r.ok) {
          if (input.value.trim() === url) addBtn.disabled = false;
        } else {
          showPreviewError(url);
        }
      })
      .catch(() => {
        // Network/CORS failure — don't block the user, oEmbed is best-effort.
        if (token === probeToken && input.value.trim() === url)
          addBtn.disabled = false;
      });
  }

  function updatePreview() {
    const url = input.value.trim();
    if (!url) {
      probeToken++;
      preview.innerHTML = `<span class="text-xs text-gray-500 dark:text-gray-400">${tjs("Preview will appear here")}</span>`;
      addBtn.disabled = true;
      return;
    }

    addBtn.disabled = false;
    const isImg = url.match(/\.(jpeg|jpg|gif|png|webp|svg)($|\?)/i);
    const isVid = url.match(/\.(mp4|webm|ogg|mov|m4v)($|\?)/i);
    const ytMatch = url.match(
      /(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/,
    );
    const viMatch = url.match(/vimeo\.com\/(\d+)/);

    if (isImg) {
      preview.innerHTML = `<img src="${url}" class="w-full h-full object-contain">`;
      addBtn.disabled = true;
      probeDirectImage(url);
    } else if (isVid) {
      preview.innerHTML = `<video src="${url}" controls class="w-full h-full object-contain"></video>`;
      addBtn.disabled = true;
      probeDirectVideo(url);
    } else if (ytMatch) {
      preview.innerHTML = `<iframe src="${embedBaseUrl('youtube')}${ytMatch[1]}" class="w-full h-full border-0" allowfullscreen></iframe>`;
      addBtn.disabled = true;
      probeOEmbed(
        "https://www.youtube.com/oembed?url=" +
          encodeURIComponent("https://www.youtube.com/watch?v=" + ytMatch[1]) +
          "&format=json",
        url,
      );
    } else if (viMatch) {
      preview.innerHTML = `<iframe src="${embedBaseUrl('vimeo')}${viMatch[1]}" class="w-full h-full border-0" allowfullscreen></iframe>`;
      addBtn.disabled = true;
      probeOEmbed(
        "https://vimeo.com/api/oembed.json?url=" + encodeURIComponent(url),
        url,
      );
    } else {
      preview.innerHTML = `<span class="text-blue-500 dark:text-blue-400 text-xs text-center p-5">${tjs("Generic Link")}<br><small class="text-gray-500 dark:text-gray-400">${url}</small></span>`;
    }
  }

  addBtn.addEventListener("click", () => {
    onSelect(input.value.trim());
    m.close();
  });

  if (initialUrl) {
    input.value = initialUrl;
    updatePreview();
  }
}
