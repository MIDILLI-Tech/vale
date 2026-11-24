/**
 * Offline snapshots for collaboration sessions (IndexedDB).
 *
 * A collaboration room only lives in the Hocuspocus process memory, and draft
 * recovery is skipped while a session exists (an HTML draft cannot be merged
 * into a CRDT). A reload while the server is unreachable would therefore drop
 * every unsaved edit. This module keeps the latest Yjs update locally and hands
 * it back to the editor for exactly that case.
 *
 * The snapshot is applied lazily — never on a normal (online) load, unlike
 * `y-indexeddb`, which restores local state on every page open. A stale browser
 * copy can then not resurrect content that was deleted on the server in the
 * meantime. Each record also stores a fingerprint of the saved HTML it was
 * taken from: if the record changed since (someone saved from another machine),
 * the snapshot is dropped instead of merged. Records survive a sync so unsaved
 * work also survives a closed tab, and expired records (older than a week) are
 * discarded. Restoring is explicit — the editor offers the snapshot in a banner
 * instead of merging it silently.
 */

import * as Y from 'yjs';

const DB_NAME = 'vl_editor_yjs';
const STORE = 'snapshots';
const DB_VERSION = 1;
const PERSIST_DELAY_MS = 1200;
const MAX_SNAPSHOTS = 20;
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

let databasePromise = null;

function openDatabase() {
  if (databasePromise) return databasePromise;

  databasePromise = new Promise((resolve) => {
    if (typeof indexedDB === 'undefined') {
      resolve(null);
      return;
    }

    let request;
    try {
      request = indexedDB.open(DB_NAME, DB_VERSION);
    } catch (_) {
      resolve(null);
      return;
    }

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'key' });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => resolve(null);
    request.onblocked = () => resolve(null);
  });

  return databasePromise;
}

/** Resolves to the request result, or the fallback when the request fails. */
function requestResult(request, fallback) {
  return new Promise((resolve) => {
    request.onsuccess = () => resolve(request.result === undefined ? fallback : request.result);
    request.onerror = () => resolve(fallback);
  });
}

/**
 * FNV-1a over the saved HTML plus its length. It only has to tell "the record
 * still looks like it did when the snapshot was taken" apart from "it changed",
 * so a fast non-cryptographic hash is enough.
 */
function fingerprint(html) {
  const text = String(html || '');
  let hash = 2166136261;
  for (let index = 0; index < text.length; index++) {
    hash ^= text.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return `${hash >>> 0}:${text.length}`;
}

async function readSnapshot(key, base) {
  const db = await openDatabase();
  if (!db) return null;

  try {
    const request = db.transaction(STORE, 'readonly').objectStore(STORE).get(key);
    const row = await requestResult(request, null);
    if (!row) return null;
    if (!row.update || row.base !== base || Date.now() - (row.savedAt || 0) > MAX_AGE_MS) {
      deleteSnapshot(key);
      return null;
    }
    return {
      update: row.update instanceof Uint8Array ? row.update : new Uint8Array(row.update),
      html: typeof row.html === 'string' ? row.html : '',
      savedAt: row.savedAt || 0,
    };
  } catch (_) {
    return null;
  }
}

async function writeSnapshot(key, base, update, html) {
  const db = await openDatabase();
  if (!db) return;

  try {
    const tx = db.transaction(STORE, 'readwrite');
    tx.onerror = () => {};
    tx.objectStore(STORE).put({ key, base, update, html, savedAt: Date.now() });
    pruneSnapshots(db);
  } catch (_) {
    /* quota, private mode — snapshots are best effort */
  }
}

async function deleteSnapshot(key) {
  const db = await openDatabase();
  if (!db) return;

  try {
    const tx = db.transaction(STORE, 'readwrite');
    tx.onerror = () => {};
    tx.objectStore(STORE).delete(key);
  } catch (_) { /* ignore */ }
}

async function pruneSnapshots(db) {
  try {
    const request = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
    const rows = await requestResult(request, []);
    if (rows.length <= MAX_SNAPSHOTS) return;

    rows.sort((a, b) => (a.savedAt || 0) - (b.savedAt || 0));
    const tx = db.transaction(STORE, 'readwrite');
    tx.onerror = () => {};
    rows.slice(0, rows.length - MAX_SNAPSHOTS).forEach((row) => tx.objectStore(STORE).delete(row.key));
  } catch (_) { /* ignore */ }
}

/**
 * Persists every Yjs update of `ydoc` (debounced) under the room name.
 *
 * @param {import('yjs').Doc} ydoc
 * @param {string} name Room name — stable per record, so it also survives reloads.
 * @param {string} baseHtml Saved HTML of the record, used as the validity check.
 * @param {() => string} getHtml Current editor HTML, stored for the restore prompt.
 * @returns {{read: () => Promise<{update: Uint8Array, html: string, savedAt: number}|null>, clear: () => void}}
 */
export function attachCollabSnapshot(ydoc, name, baseHtml, getHtml) {
  const base = fingerprint(baseHtml);
  let pending = [];
  let timer = null;

  const flush = () => {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    const updates = pending;
    pending = [];
    if (!updates.length) return;

    // Each Yjs update is incremental. Keep every one that arrived during the
    // debounce window: storing only the last would write a partial document.
    const update = updates.length === 1 ? updates[0] : Y.mergeUpdates(updates);
    if (!update || !update.byteLength) return;
    const html = typeof getHtml === 'function' ? getHtml() : '';
    writeSnapshot(name, base, update, html);
  };

  ydoc.on('update', (update) => {
    pending.push(update);
    if (timer) clearTimeout(timer);
    timer = setTimeout(flush, PERSIST_DELAY_MS);
  });

  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', () => {
    if (window.document.visibilityState === 'hidden') flush();
  });

  return {
    read: () => readSnapshot(name, base),
    clear: () => {
      pending = [];
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      deleteSnapshot(name);
    },
  };
}
