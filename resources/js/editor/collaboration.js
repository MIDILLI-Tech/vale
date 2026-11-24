/**
 * Optional real-time collaboration for the block editor (Yjs + Hocuspocus).
 *
 * Configuration comes from `window.VL_COLLAB` (admin/layout.twig): whether the
 * feature is on, the WebSocket URL, the shared secret and an optional room
 * prefix. A session is only created for an already-saved record
 * (`/admin/pages/1/edit`), because the Yjs room needs a stable document name —
 * create screens stay single-user.
 *
 * The HTML textarea remains the persistence format: the editor keeps writing
 * `editor.getHTML()` into it, so the normal form save is unchanged. Unsaved
 * edits survive an offline reload through a local Yjs snapshot
 * (`collab-snapshot.js`), applied directly when the server is unreachable and
 * offered for restore after a normal sync (closed tab, deliberate reopen).
 */
import * as Y from 'yjs';
import { HocuspocusProvider } from '@hocuspocus/provider';
import { relativePositionToAbsolutePosition, ySyncPluginKey } from '@tiptap/y-tiptap';
import { attachCollabSnapshot } from './collab-snapshot.js';

const EDIT_PATH = /\/\d+\/edit$/;

/**
 * y-prosemirror only accepts hex colors for remote carets and selections.
 * Anything else (hsl, color names) is dropped with an
 * "unsupported color format" warning, so names map onto a fixed palette.
 */
const USER_COLORS = [
  '#2563eb', '#dc2626', '#059669', '#d97706',
  '#7c3aed', '#db2777', '#0891b2', '#65a30d',
];

/** Session of the editor on this page, so block views can publish their position. */
let activeSession = null;

/**
 * Optional namespace so several Vale installations can share one server:
 * "site-a/admin/pages/5/edit" never collides with "site-b/...".
 */
function roomPrefix() {
  return String(window.VL_COLLAB?.prefix || '')
    .trim()
    .replace(/^\/+|\/+$/g, '')
    .replace(/\s+/g, '-');
}

function documentName() {
  const path = (window.location.pathname || '').replace(/\/+$/, '');
  if (!EDIT_PATH.test(path)) return null;
  const name = path.replace(/^\/+/, '');
  const prefix = roomPrefix();
  return prefix ? `${prefix}/${name}` : name;
}

function userColor(name) {
  let hash = 0;
  for (const char of String(name)) hash = (hash * 31 + char.charCodeAt(0)) % USER_COLORS.length;
  return USER_COLORS[hash];
}

function translate(text, replacements = {}) {
  return typeof window.tjs === 'function' ? window.tjs(text, replacements) : text;
}

function initials(name) {
  return String(name)
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}

/**
 * Stable presence identity: the account id when the awareness state carries
 * one, the display name otherwise (older clients, or a config without a user).
 */
function presenceIdentity(user) {
  const id = Number(user?.id) || 0;
  return id > 0 ? `id:${id}` : `name:${String(user?.name || '').toLowerCase()}`;
}

/**
 * @param {string} initialHtml Saved HTML the room is seeded from. It also
 *   fingerprints the local offline snapshot, so a record saved elsewhere in
 *   the meantime invalidates that snapshot instead of merging a stale copy.
 * @returns {{document: Y.Doc, provider: HocuspocusProvider, user: {id: number, name: string, color: string}, name: string}|null}
 */
export function createCollaborationSession(initialHtml = '') {
  const config = window.VL_COLLAB;
  if (!config || !config.enabled || !config.url) return null;

  const name = documentName();
  if (!name) return null;

  const document = new Y.Doc();
  const provider = new HocuspocusProvider({
    url: config.url,
    name,
    document,
    token: config.secret || '',
    onAuthenticationFailed: ({ reason }) => {
      console.warn('[Vale] Collaboration authentication failed:', reason);
    },
  });

  const userName = config.user?.name || 'Admin';

  const session = {
    document,
    provider,
    name,
    // CollaborationCaret publishes this object verbatim as the awareness user,
    // so the id is what lets several tabs of one account share a single
    // presence entry.
    user: { id: Number(config.user?.id) || 0, name: userName, color: userColor(userName) },
  };
  activeSession = session;
  return session;
}

/**
 * Publishes the block a user is editing through a nested contentEditable
 * (Media + Text) as an awareness hint. That column lives outside the
 * ProseMirror document — its HTML is a node attribute — so the cursor cannot
 * describe a position inside it and follow/jump would stay on the last real
 * selection. `null` clears the hint again.
 */
export function setEditingBlock(editor, pos) {
  const awareness = activeSession?.provider?.awareness;
  if (!awareness || typeof awareness.setLocalStateField !== 'function') return;

  if (pos === null) {
    awareness.setLocalStateField('editingBlock', null);
    return;
  }
  if (typeof pos !== 'number') return;

  try {
    // A top-level block index is enough to find the block again and, unlike a
    // Yjs relative position taken at an atom node, cannot resolve one position
    // past it. Refreshed while the column keeps focus, so inserts above it are
    // picked up on the next keystroke.
    const index = editor.state.doc.resolve(pos).index(0);
    if (awareness.getLocalState()?.editingBlock?.index === index) return;
    awareness.setLocalStateField('editingBlock', { index });
  } catch (_) {
    /* the block moved while its view was updating */
  }
}

/**
 * Seeds the Yjs room from the saved HTML the first time it is opened. Runs
 * only after the provider synced, so two tabs opening the same empty room do
 * not both write the initial content.
 */
export function seedCollaborationContent(editor, session, initialHtml) {
  if (!session) return;

  // Attached here because the snapshot stores the editor HTML next to the Yjs
  // update; the session itself is created before the editor exists.
  if (!session.snapshot) {
    session.snapshot = attachCollabSnapshot(
      session.document,
      session.name,
      initialHtml,
      () => editor.getHTML(),
    );
  }

  let seeded = false;
  const seed = () => {
    if (seeded || !initialHtml) return;
    if (session.document.getXmlFragment('default').length > 0) return;
    seeded = true;
    editor.commands.setContent(initialHtml, { emitUpdate: false });
  };

  if (session.provider.synced) {
    seed();
    return;
  }

  session.provider.on('synced', seed);

  // The server can be down while the editor is open. Without a fallback the
  // canvas stays blank and saving would wipe the record, so seed the saved
  // content locally after a short grace period. Anything typed offline merges
  // into the room once the connection returns.
  setTimeout(async () => {
    if (session.provider.synced || seeded) return;

    // A reload that happens while the server is still unreachable would
    // otherwise lose the edits that only lived in the room. The snapshot is
    // tied to the saved HTML it was taken from, so newer database content
    // wins over a stale local copy.
    const stored = await session.snapshot?.read?.();
    if (session.provider.synced || seeded) return;

    if (stored) {
      try {
        Y.applyUpdate(session.document, stored.update, 'offline-snapshot');
      } catch (_) {
        /* unreadable snapshot — fall back to the saved content */
      }
      if (session.document.getXmlFragment('default').length > 0) {
        seeded = true;
        return;
      }
    }

    seed();
  }, 3000);
}

function normalizeHtml(html) {
  return String(html || '').replace(/\r\n/g, '\n').trim();
}

function formatSavedAt(timestamp) {
  try {
    return new Date(timestamp).toLocaleString(document.documentElement.lang || undefined);
  } catch (_) {
    return new Date(timestamp).toLocaleString();
  }
}

/**
 * Offers a locally stored snapshot for restore once the room is synced.
 *
 * The snapshot survives the sync (that is what makes unsaved work survive a
 * closed tab), but merging it automatically could re-add content someone
 * deleted on the server. The prompt is skipped when the room already contains
 * every operation in the snapshot, and "Discard changes" deletes the record.
 */
export function initCollaborationSnapshotPrompt(editor, session, wrapper) {
  if (!session?.snapshot || !wrapper) return;

  let banner = null;
  let asked = false;

  const hide = () => {
    if (banner?.parentNode) banner.parentNode.removeChild(banner);
    banner = null;
  };

  const show = (stored) => {
    banner = document.createElement('div');
    banner.className = 'vl-draft-banner';
    banner.setAttribute('role', 'status');

    const icon = document.createElement('span');
    icon.className = 'vl-draft-banner-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '⚠';

    const text = document.createElement('span');
    text.className = 'vl-draft-banner-text';

    const title = document.createElement('span');
    title.className = 'vl-draft-banner-title';
    title.textContent = translate('Unsaved changes found');

    const detail = document.createElement('span');
    detail.textContent = translate(
      'Changes saved in this browser at :time can be restored into the shared document.',
      { ':time': formatSavedAt(stored.savedAt) },
    );

    text.append(title, detail);

    const actions = document.createElement('span');
    actions.className = 'vl-draft-banner-actions';

    const restore = document.createElement('button');
    restore.type = 'button';
    restore.className = 'vl-draft-btn vl-draft-btn--primary';
    restore.textContent = translate('Restore changes');
    restore.addEventListener('click', () => {
      try {
        Y.applyUpdate(session.document, stored.update, 'restored-snapshot');
      } catch (_) {
        /* unreadable snapshot — leave the room untouched */
      }
      hide();
    });

    const discard = document.createElement('button');
    discard.type = 'button';
    discard.className = 'vl-draft-btn vl-draft-btn--danger';
    discard.textContent = translate('Discard changes');
    discard.addEventListener('click', () => {
      session.snapshot.clear();
      hide();
    });

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'vl-draft-banner-close';
    dismiss.setAttribute('aria-label', translate('Dismiss'));
    dismiss.title = translate('Dismiss');
    dismiss.textContent = '×';
    dismiss.addEventListener('click', hide);

    actions.append(restore, discard, dismiss);
    banner.append(icon, text, actions);

    const container = document.querySelector('main') || wrapper.parentNode || document.body;
    container.insertBefore(banner, container.firstChild);
  };

  const ask = async () => {
    if (asked || !document.contains(editor.view.dom)) return;
    asked = true;

    const stored = await session.snapshot.read();
    if (!stored?.update) return;

    // Nothing to offer when the room already shows the stored content: the
    // snapshot was written from the seeded document and nobody edited it.
    if (stored.html && normalizeHtml(stored.html) === normalizeHtml(editor.getHTML())) return;

    show(stored);
  };

  if (session.provider.synced) ask();
  else session.provider.on('synced', ask);
}

/** Resolves a published top-level block index to a position in the local doc. */
function blockStartPosition(doc, hint) {
  const index = Number(hint?.index);
  if (!Number.isInteger(index) || index < 0 || index >= doc.childCount) return null;
  let pos = 0;
  for (let i = 0; i < index; i += 1) pos += doc.child(i).nodeSize;
  return pos;
}

/** Maps a remote awareness cursor onto the local ProseMirror document. */
function remoteCursorPos(editor, state) {
  const ystate = ySyncPluginKey.getState(editor.state);
  if (!ystate?.doc || !ystate.type || !ystate.binding?.mapping) return null;

  const resolve = (reference) => {
    const head = reference?.head || reference;
    if (!head) return null;
    try {
      return relativePositionToAbsolutePosition(
        ystate.doc,
        ystate.type,
        Y.createRelativePositionFromJSON(head),
        ystate.binding.mapping,
      );
    } catch (_) {
      return null;
    }
  };

  // A nested contentEditable block (Media + Text) publishes its top-level block
  // index; there the awareness cursor is stale by design. Falling back keeps
  // following alive when that block no longer exists on this side.
  const block = blockStartPosition(editor.state.doc, state?.editingBlock);
  const cursor = resolve(state?.cursor);
  const pos = block ?? cursor;

  if (pos === null) return null;
  return Math.max(0, Math.min(pos, editor.state.doc.content.size - 1));
}

function scrollToPos(editor, pos, behavior) {
  try {
    // nodeDOM resolves a block (follow can target a Media + Text block, which
    // has no text position); domAtPos covers plain text positions.
    let node = editor.view.nodeDOM(pos);
    if (!node?.nodeType) {
      node = editor.view.domAtPos(pos).node;
    }
    const el = node?.nodeType === Node.TEXT_NODE ? node.parentElement : node;
    el?.scrollIntoView?.({ block: 'center', behavior });
  } catch (_) {
    /* position no longer exists (remote doc changed) */
  }
}

/**
 * Renders "N online" plus one colored initial per person directly above the
 * editing canvas, fed by the Yjs awareness state. Several tabs of the same
 * account share one entry instead of counting as separate editors.
 *
 * Clicking another editor's chip jumps to their caret once; clicking the same
 * chip again starts following it (the viewport tracks their cursor) and a
 * third click stops. Your own chip does the same for your other tab.
 */
export function initCollaborationPresence(session, wrapper, editorEl, editor) {
  if (!session || !wrapper || !editorEl || !editor) return;

  const awareness = session.provider.awareness;
  if (!awareness) return;

  const bar = document.createElement('div');
  bar.className = 'vl-collab-presence';
  bar.setAttribute('role', 'status');
  bar.setAttribute('aria-live', 'polite');

  // Click state per person: null = idle, key = jumped once, and the same key
  // in followingUserId = follow mode. Stopping follow resets both, so the next
  // click always starts with a fresh jump. Keys rather than client ids keep the
  // state alive when the followed person switches between their tabs.
  let jumpedUserId = null;
  let followingUserId = null;
  let followFrame = null;
  let connectionState = 'connecting';
  // The socket reports `connected` before the first sync handshake finishes;
  // awareness is not trustworthy until `synced`, so the strip must wait for it.
  let synced = Boolean(session.provider.synced);
  let peopleByKey = new Map();

  const isSelfPerson = (person) => person.clientIds.includes(awareness.clientID);

  // The connection a jump/follow targets. Our own entry points at our other tab
  // (so following it still works while testing in one browser), never the tab we
  // are in; for everyone else the tab that currently reports a caret wins, so
  // following survives the other tab being in the background.
  const targetClientId = (person) => {
    const others = person.clientIds.filter((clientId) => clientId !== awareness.clientID);
    const pool = isSelfPerson(person) ? others : person.clientIds;
    return pool.find((clientId) => awareness.getStates().get(clientId)?.cursor)
      ?? pool[0]
      ?? awareness.clientID;
  };

  const hasOtherTab = (person) => isSelfPerson(person)
    && targetClientId(person) !== awareness.clientID;

  const titleFor = (person) => {
    const ownTab = hasOtherTab(person);
    let title;
    if (followingUserId === person.key) {
      title = ownTab
        ? translate('Following your other tab — click to stop')
        : translate('Following :name — click to stop', { ':name': person.user.name });
    } else if (jumpedUserId === person.key) {
      title = ownTab
        ? translate('Click again to follow your other tab')
        : translate('Click again to follow :name', { ':name': person.user.name });
    } else if (ownTab) {
      title = translate('Jump to your other tab');
    } else if (isSelfPerson(person)) {
      title = person.user.name;
    } else {
      title = translate('Click to jump to :name', { ':name': person.user.name });
    }
    return person.tabs > 1
      ? `${title} · ${translate('%d tabs', { '%d': person.tabs })}`
      : title;
  };

  const follow = () => {
    if (followingUserId === null || followFrame !== null) return;
    followFrame = requestAnimationFrame(() => {
      followFrame = null;
      const person = peopleByKey.get(followingUserId);
      if (!person) return;
      const state = awareness.getStates().get(targetClientId(person));
      const pos = remoteCursorPos(editor, state);
      if (pos !== null) scrollToPos(editor, pos, 'auto');
    });
  };

  const isOnline = () => connectionState === 'connected' && synced;

  const render = () => {
    // One entry per account, not per connection: a second tab of the same
    // person must not add a second chip or inflate the online count.
    const people = new Map();
    awareness.getStates().forEach((state, clientId) => {
      if (!state?.user?.name) return;
      const key = presenceIdentity(state.user);
      const person = people.get(key) ?? { key, user: state.user, clientIds: [], tabs: 0 };
      person.clientIds.push(clientId);
      person.tabs += 1;
      people.set(key, person);
    });
    peopleByKey = people;

    // Our own chip first, then alphabetical, so caret changes never reshuffle
    // the strip.
    const ordered = [...people.values()].sort((a, b) => {
      const selfA = isSelfPerson(a);
      const selfB = isSelfPerson(b);
      if (selfA !== selfB) return selfA ? -1 : 1;
      return a.user.name.localeCompare(b.user.name);
    });

    const online = isOnline();
    if (!online) {
      followingUserId = null;
      jumpedUserId = null;
    }

    if (followingUserId !== null && !people.has(followingUserId)) {
      followingUserId = null;
    }
    if (jumpedUserId !== null && !people.has(jumpedUserId)) {
      jumpedUserId = null;
    }

    bar.textContent = '';

    // Disconnected: the awareness map only holds our own state, so a "1 online"
    // count would be misleading. Show a hoverable warning instead and keep the
    // remaining presence UI for when the socket is back.
    if (!online) {
      const alert = document.createElement('span');
      alert.className = 'vl-collab-presence-alert';
      alert.textContent = '!';
      // `connected` alone is not enough: until the room synced there is no
      // presence to show, and warning about a server that is still completing
      // the handshake was what made the strip look broken on first load.
      const waiting = connectionState === 'connecting'
        || (connectionState === 'connected' && !synced);
      alert.title = waiting
        ? translate('Connecting to the collaboration server…')
        : translate('Collaboration not available — changes are saved when you save the form.');
      alert.setAttribute('role', 'status');
      alert.setAttribute('aria-label', alert.title);
      bar.appendChild(alert);
      return;
    }

    if (ordered.length === 0) return;

    const count = document.createElement('span');
    count.className = 'vl-collab-presence-count';
    count.textContent = translate('%d online', { '%d': ordered.length });
    bar.appendChild(count);

    ordered.forEach((person) => {
      const isSelf = isSelfPerson(person);
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'vl-collab-presence-user'
        + (isSelf ? ' is-self' : '')
        + (isSelf && hasOtherTab(person) ? ' has-other-tab' : '')
        + (followingUserId === person.key ? ' is-following' : '')
        + (followingUserId !== person.key && jumpedUserId === person.key ? ' is-jumped' : '');
      chip.textContent = initials(person.user.name);
      chip.style.setProperty('--vl-collab-user-color', person.user.color || '#2563eb');
      chip.title = titleFor(person);
      chip.setAttribute('aria-label', chip.title);
      chip.dataset.vlCollabKey = person.key;

      bar.appendChild(chip);
    });
  };

  // Runs on the press rather than on the click: every remote caret move
  // re-renders the strip, and a chip replaced between mousedown and mouseup
  // never receives its click — the reason stopping a follow sometimes needed a
  // second press.
  const activatePerson = (person) => {
    // Following this person: stop and return to idle so the next press is a
    // plain jump again.
    if (followingUserId === person.key) {
      followingUserId = null;
      jumpedUserId = null;
      render();
      return;
    }

    const pos = remoteCursorPos(editor, awareness.getStates().get(targetClientId(person)));
    if (pos !== null) scrollToPos(editor, pos, 'smooth');

    // First press jumps, a second press on the same chip follows.
    if (jumpedUserId === person.key) {
      followingUserId = person.key;
    } else {
      followingUserId = null;
      jumpedUserId = person.key;
    }
    render();
  };

  // Delegated on the strip so the listener survives the chip rebuilds; the
  // press is handled before a repaint can replace the element under the
  // pointer.
  const onChipPress = (event) => {
    if (event.type === 'keydown') {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
    } else if (event.button !== 0) {
      return;
    }

    const chip = event.target.closest?.('.vl-collab-presence-user');
    if (!chip) return;

    const person = peopleByKey.get(chip.dataset.vlCollabKey);
    if (!person) return;
    // Our own chip only acts while a second tab of this account is open.
    if (isSelfPerson(person) && !hasOtherTab(person)) return;

    activatePerson(person);
  };
  bar.addEventListener('pointerdown', onChipPress);
  bar.addEventListener('keydown', onChipPress);

  session.provider.on('status', ({ status }) => {
    connectionState = status;
    synced = session.provider.synced;
    render();
  });

  // Without this the strip kept its pre-sync state until an unrelated caret
  // change re-rendered it (clicking into the editor was that trigger).
  session.provider.on('synced', () => {
    synced = true;
    render();
  });

  awareness.on('change', () => {
    render();
    follow();
  });
  render();

  // The strip sticks under the toolbar. When the toolbar itself is sticky or
  // pinned it covers the top of the wrapper, so the strip offsets by its
  // measured height instead of overlapping it.
  const toolbarEl = wrapper.querySelector('.vl-editor-toolbar');
  const syncStickyOffset = () => {
    const pinned = Boolean(toolbarEl) && (
      wrapper.classList.contains('is-sticky') || toolbarEl.classList.contains('is-pinned')
    );
    bar.style.setProperty('--vl-collab-top', pinned ? `${toolbarEl.offsetHeight}px` : '0px');
  };
  if (toolbarEl && typeof ResizeObserver === 'function') {
    new ResizeObserver(syncStickyOffset).observe(toolbarEl);
  }
  new MutationObserver(syncStickyOffset).observe(wrapper, { attributes: true, attributeFilter: ['class'] });
  syncStickyOffset();

  wrapper.insertBefore(bar, editorEl);
}
