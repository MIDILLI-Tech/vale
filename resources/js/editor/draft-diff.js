/**
 * Draft comparison modal: local (unsaved) draft vs the saved version.
 *
 * The alignment is a Myers shortest-edit-script over word tokens (tags are
 * tokens too, so markup changes are visible). Rows mirror the revision diff:
 * one row per block, `-`/`+` markers, and word-level highlighting when the
 * same row changed on both sides. Very large documents fall back to
 * block-level tokens to keep the diff cheap.
 */
import { createModal } from './ui/modal.js';

const WORD_TOKEN_LIMIT = 6000;
const MAX_EDIT_DISTANCE = 1200;
const BLOCK_TAG = /^<\/?(?:p|div|h[1-6]|li|ul|ol|dl|dt|dd|tr|td|th|thead|tbody|tfoot|table|blockquote|figure|figcaption|pre|hr|address|article|section|header|footer|aside|nav)\b/i;
const VOID_BLOCK_TAG = /^<hr\b/i;
const TAG_TOKEN = /^<[^>]*>$/;
// Elements that carry content without text: an added image is a real change
// even though the visible words did not move.
const CONTENT_TAG = /^<(?:img|video|audio|iframe|embed|object|canvas|svg|picture|source|track|hr)\b/i;

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function tokenizeWords(html) {
  return html.match(/<[^>]*>|[^\s<]+|\s+/g) || [];
}

function tokenizeBlocks(html) {
  return html
    .split(/(?=<(?:p|div|h[1-6]|li|tr|blockquote|figure|table|thead|tbody|ul|ol|hr|br)\b)/i)
    .flatMap(part => part.match(/<[^>]*>|[^\s<]+|\s+/g) || [])
    .slice(0, WORD_TOKEN_LIMIT * 2);
}

/**
 * Myers diff — returns a flat list of { type: 'equal'|'del'|'ins', token }.
 * Returns null when the edit distance is too large for a useful word diff.
 */
function diffTokens(a, b) {
  const n = a.length;
  const m = b.length;
  const max = n + m;
  if (max === 0) return [];

  const trace = [];
  const v = new Map([[1, 0]]);

  for (let d = 0; d <= max; d++) {
    if (d > MAX_EDIT_DISTANCE) return null;
    trace.push(new Map(v));
    for (let k = -d; k <= d; k += 2) {
      let x;
      if (k === -d || (k !== d && (v.get(k - 1) ?? -Infinity) < (v.get(k + 1) ?? -Infinity))) {
        x = v.get(k + 1) ?? 0;
      } else {
        x = (v.get(k - 1) ?? 0) + 1;
      }
      let y = x - k;
      while (x < n && y < m && a[x] === b[y]) { x++; y++; }
      v.set(k, x);
      if (x >= n && y >= m) return backtrack(trace, a, b);
    }
  }
  return null;
}

function backtrack(trace, a, b) {
  let x = a.length;
  let y = b.length;
  const ops = [];

  for (let d = trace.length - 1; d >= 0; d--) {
    const v = trace[d];
    const k = x - y;
    let prevK;
    if (k === -d || (k !== d && (v.get(k - 1) ?? -Infinity) < (v.get(k + 1) ?? -Infinity))) {
      prevK = k + 1;
    } else {
      prevK = k - 1;
    }
    const prevX = v.get(prevK) ?? 0;
    const prevY = prevX - prevK;

    while (x > prevX && y > prevY) {
      ops.push({ type: 'equal', token: a[x - 1] });
      x--;
      y--;
    }
    if (d > 0) {
      if (x === prevX) {
        ops.push({ type: 'ins', token: b[y - 1] });
        y--;
      } else {
        ops.push({ type: 'del', token: a[x - 1] });
        x--;
      }
    }
  }
  return ops.reverse();
}

function isLineBreak(token) {
  return token.includes('\n') || /^<br\b/i.test(token) || VOID_BLOCK_TAG.test(token);
}

/**
 * Groups ops into rows, one per block element. Equal tokens land on both
 * sides so unchanged rows stay aligned; a row that has both sides renders as
 * a change pair.
 */
function buildRows(ops) {
  const rows = [];
  let row = { saved: [], draft: [] };

  const flush = () => {
    if (row.saved.join('').trim() !== '' || row.draft.join('').trim() !== '') rows.push(row);
    row = { saved: [], draft: [] };
  };

  for (const op of ops) {
    const opens = (BLOCK_TAG.test(op.token) && !op.token.startsWith('</')) || isLineBreak(op.token);
    if (opens) flush();

    if (op.type === 'equal') {
      row.saved.push(op.token);
      row.draft.push(op.token);
    } else if (op.type === 'del') {
      row.saved.push(op.token);
    } else {
      row.draft.push(op.token);
    }

    const closes = (BLOCK_TAG.test(op.token) && op.token.startsWith('</')) || isLineBreak(op.token);
    if (closes) flush();
  }
  flush();

  return rows;
}

/**
 * Text a reader actually sees in a row: tags dropped, common entities
 * decoded, whitespace collapsed. Rows whose visible text matches are treated
 * as unchanged, so whitespace and markup-only differences no longer render as
 * changes the counters cannot explain.
 */
function visibleText(html) {
  return String(html)
    .replace(/<(?:img|video|audio|iframe|embed|object|canvas|svg|picture|source|track|hr)[^>]*>/gi, (tag) => ` [${tag.replace(/[<>]/g, '').replace(/s+/g, ' ').trim()}] `)
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;|&#160;/gi, ' ')
    .replace(/&quot;/gi, '"')
    .replace(/&#0?39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&amp;/gi, '&')
    .replace(/\s+/g, ' ')
    .trim();
}

function isContentToken(token) {
  if (token.trim() === '') return false;
  return !TAG_TOKEN.test(token) || CONTENT_TAG.test(token);
}

function countWords(tokens) {
  let words = 0;
  for (const token of tokens) {
    if (isContentToken(token)) words++;
  }
  return words;
}

function lineHtml(type, marker, inner) {
  return `<div class="vl-diff-line vl-diff-${type}"><span class="vl-diff-marker">${marker}</span> ${inner}</div>`;
}

/**
 * Renders one side of a change pair: unchanged tokens stay plain, the side's
 * own edits are wrapped so only the differing words are highlighted.
 */
function sideHtml(ops, keep) {
  const wrap = keep === 'del' ? 'vl-w-del' : 'vl-w-add';
  let html = '';
  let open = false;
  let buffer = '';

  const flush = () => {
    if (open && buffer.trim() !== '') {
      const lead = buffer.match(/^\s*/)[0];
      const trail = buffer.match(/\s*$/)[0];
      const core = buffer.slice(lead.length, buffer.length - trail.length);
      html += lead + (core ? `<span class="${wrap}">${core}</span>` : '') + trail;
    } else if (open) {
      html += buffer;
    }
    open = false;
    buffer = '';
  };

  for (const op of ops) {
    if (op.type === 'equal') {
      flush();
      html += escapeHtml(op.token);
      continue;
    }
    if (op.type !== keep) continue;
    open = true;
    buffer += escapeHtml(op.token);
  }
  flush();

  return html;
}

function renderRow(row) {
  const saved = row.saved.join('');
  const draft = row.draft.join('');
  const before = saved.trim();
  const after = draft.trim();

  if (visibleText(saved) === visibleText(draft)) {
    return { html: lineHtml('same', '', escapeHtml(before)), removed: 0, added: 0, changed: false };
  }
  if (after === '') {
    return { html: lineHtml('del', '-', escapeHtml(before)), removed: countWords(row.saved), added: 0, changed: true };
  }
  if (before === '') {
    return { html: lineHtml('add', '+', escapeHtml(after)), removed: 0, added: countWords(row.draft), changed: true };
  }

  const ops = diffTokens(row.saved, row.draft) || [
    ...row.saved.map(token => ({ type: 'del', token })),
    ...row.draft.map(token => ({ type: 'ins', token })),
  ];

  let removed = 0;
  let added = 0;
  for (const op of ops) {
    if (op.type === 'del' && isContentToken(op.token)) removed++;
    if (op.type === 'ins' && isContentToken(op.token)) added++;
  }

  return {
    html: lineHtml('del', '-', sideHtml(ops, 'del')) + lineHtml('add', '+', sideHtml(ops, 'ins')),
    removed,
    added,
    changed: true,
  };
}

/**
 * Builds the line-based diff markup plus the changed-word counters.
 *
 * @param {string} savedHtml
 * @param {string} draftHtml
 * @returns {{html: string, removed: number, added: number, changed: boolean}}
 */
export function renderDraftDiff(savedHtml, draftHtml) {
  let savedTokens = tokenizeWords(savedHtml || '');
  let draftTokens = tokenizeWords(draftHtml || '');
  if (savedTokens.length + draftTokens.length > WORD_TOKEN_LIMIT) {
    savedTokens = tokenizeBlocks(savedHtml || '');
    draftTokens = tokenizeBlocks(draftHtml || '');
  }

  const ops = diffTokens(savedTokens, draftTokens);
  if (!ops) {
    return {
      html: lineHtml('del', '-', escapeHtml(savedHtml || '')) + lineHtml('add', '+', escapeHtml(draftHtml || '')),
      removed: savedHtml ? 1 : 0,
      added: draftHtml ? 1 : 0,
      changed: (savedHtml || '') !== (draftHtml || ''),
    };
  }

  const result = { html: '', removed: 0, added: 0, changed: false };
  for (const row of buildRows(ops)) {
    const rendered = renderRow(row);
    result.html += rendered.html;
    result.removed += rendered.removed;
    result.added += rendered.added;
    result.changed = result.changed || rendered.changed;
  }
  return result;
}

/**
 * Opens the comparison dialog.
 *
 * @param {{savedHtml: string, draftHtml: string, savedAt?: number, onDiscard: Function}} opts
 */
export function openDraftDiff({ savedHtml, draftHtml, savedAt, onDiscard }) {
  const modal = createModal({
    className: 'vl-modal--draft-diff',
    zIndex: 10050,
    structured: true,
  });

  let diffBody = '';
  let countsHtml = '';
  try {
    const diff = renderDraftDiff(savedHtml, draftHtml);
    diffBody = diff.changed
      ? `<div class="vl-draft-diff-body">${diff.html}</div>`
      : `<div class="vl-draft-diff-body"><div class="vl-diff-line vl-diff-same"><span class="vl-diff-marker"></span> ${escapeHtml(tjs('No changes.'))}</div></div>`;
    countsHtml = `<span class="vl-draft-diff-counts">−${diff.removed} / +${diff.added}</span>`;
  } catch (_) {
    diffBody = `<p class="vl-draft-diff-sub">${escapeHtml(tjs('The draft could not be compared. Showing the saved version only.'))}</p>`
      + `<div class="vl-draft-diff-body">${escapeHtml(savedHtml)}</div>`;
  }

  const savedLabel = savedAt
    ? `<span class="vl-draft-diff-meta">${escapeHtml(tjs('Saved at :time', { ':time': new Date(savedAt).toLocaleString(document.documentElement.lang || undefined) }))}</span>`
    : '';

  modal.modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">${escapeHtml(tjs('Draft comparison'))}</h3>
    </div>
    <div class="vl-editor-modal-body">
      <p class="vl-draft-diff-sub">${escapeHtml(tjs('Changes made in this browser (not saved yet) compared with the saved version.'))} ${savedLabel}</p>
      <div class="vl-draft-diff-legend">
        <span class="vl-draft-diff-legend-item"><span class="vl-diff-swatch-del"></span> ${escapeHtml(tjs('Saved version'))}</span>
        <span class="vl-draft-diff-legend-item"><span class="vl-diff-swatch-add"></span> ${escapeHtml(tjs('Draft'))}</span>
        ${countsHtml}
      </div>
      ${diffBody}
    </div>
    <div class="vl-editor-modal-foot">
      <button type="button" class="vl-draft-btn vl-draft-btn--danger" data-vl-diff-discard>${escapeHtml(tjs('Discard draft'))}</button>
      <button type="button" class="vl-draft-btn" data-vl-diff-close>${escapeHtml(tjs('Close'))}</button>
    </div>`;

  modal.modal.querySelector('[data-vl-diff-close]')?.addEventListener('click', () => modal.close());
  modal.modal.querySelector('[data-vl-diff-discard]')?.addEventListener('click', () => {
    modal.close();
    onDiscard();
  });

  return modal;
}
