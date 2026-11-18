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
 * `editor.getHTML()` into it, so the normal form save is unchanged.
 */
import * as Y from 'yjs';
import { HocuspocusProvider } from '@hocuspocus/provider';
import { relativePositionToAbsolutePosition, ySyncPluginKey } from '@tiptap/y-tiptap';

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
 * @returns {{document: Y.Doc, provider: HocuspocusProvider, user: {name: string, color: string}, name: string}|null}
 */
export function createCollaborationSession() {
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

  return {
    document,
    provider,
    name,
    user: { name: userName, color: userColor(userName) },
  };
}

/**
 * Seeds the Yjs room from the saved HTML the first time it is opened. Runs
 * only after the provider synced, so two tabs opening the same empty room do
 * not both write the initial content.
 */
export function seedCollaborationContent(editor, session, initialHtml) {
  if (!session || !initialHtml) return;

  let seeded = false;
  const seed = () => {
    if (seeded) return;
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
  setTimeout(() => {
    if (!session.provider.synced) seed();
  }, 3000);
}

/** Maps a remote awareness cursor onto the local ProseMirror document. */
function remoteCursorPos(editor, state) {
  const cursor = state?.cursor;
  if (!cursor?.head) return null;

  const ystate = ySyncPluginKey.getState(editor.state);
  if (!ystate?.doc || !ystate.type || !ystate.binding?.mapping) return null;

  const pos = relativePositionToAbsolutePosition(
    ystate.doc,
    ystate.type,
    Y.createRelativePositionFromJSON(cursor.head),
    ystate.binding.mapping,
  );
  if (pos === null) return null;

  return Math.max(0, Math.min(pos, editor.state.doc.content.size - 1));
}

function scrollToPos(editor, pos, behavior) {
  try {
    const found = editor.view.domAtPos(pos);
    const node = found.node.nodeType === Node.TEXT_NODE ? found.node.parentElement : found.node;
    node?.scrollIntoView({ block: 'center', behavior });
  } catch (_) {
    /* position no longer exists (remote doc changed) */
  }
}

/**
 * Renders "N online" plus one colored initial per connected editor directly
 * above the editing canvas, fed by the Yjs awareness state.
 *
 * Clicking another editor's chip jumps to their caret once; clicking the same
 * chip again starts following it (the viewport tracks their cursor) and a
 * third click stops.
 */
export function initCollaborationPresence(session, wrapper, editorEl, editor) {
  if (!session || !wrapper || !editorEl || !editor) return;

  const awareness = session.provider.awareness;
  if (!awareness) return;

  const bar = document.createElement('div');
  bar.className = 'vl-collab-presence';
  bar.setAttribute('role', 'status');
  bar.setAttribute('aria-live', 'polite');

  // Click state per editor: null = idle, id = jumped once, and the same id in
  // followingClientId = follow mode. Stopping follow resets both, so the next
  // click always starts with a fresh jump.
  let jumpedClientId = null;
  let followingClientId = null;
  let followFrame = null;

  const titleFor = (clientId, user) => {
    if (clientId === awareness.clientID) return user.name;
    if (followingClientId === clientId) {
      return translate('Following :name — click to stop', { ':name': user.name });
    }
    if (jumpedClientId === clientId) {
      return translate('Click again to follow :name', { ':name': user.name });
    }
    return translate('Click to jump to :name', { ':name': user.name });
  };

  const follow = () => {
    if (followingClientId === null || followFrame !== null) return;
    followFrame = requestAnimationFrame(() => {
      followFrame = null;
      const state = awareness.getStates().get(followingClientId);
      const pos = remoteCursorPos(editor, state);
      if (pos !== null) scrollToPos(editor, pos, 'auto');
    });
  };

  const render = () => {
    const users = [];
    awareness.getStates().forEach((state, clientId) => {
      if (state?.user?.name) users.push({ clientId, user: state.user });
    });

    if (followingClientId !== null && !users.some((entry) => entry.clientId === followingClientId)) {
      followingClientId = null;
    }
    if (jumpedClientId !== null && !users.some((entry) => entry.clientId === jumpedClientId)) {
      jumpedClientId = null;
    }

    bar.textContent = '';
    if (users.length === 0) return;

    const count = document.createElement('span');
    count.className = 'vl-collab-presence-count';
    count.textContent = translate('%d online', { '%d': users.length });
    bar.appendChild(count);

    users.forEach(({ clientId, user }) => {
      const isSelf = clientId === awareness.clientID;
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'vl-collab-presence-user'
        + (isSelf ? ' is-self' : '')
        + (followingClientId === clientId ? ' is-following' : '')
        + (followingClientId !== clientId && jumpedClientId === clientId ? ' is-jumped' : '');
      chip.textContent = initials(user.name);
      chip.style.setProperty('--vl-collab-user-color', user.color || '#2563eb');
      chip.title = titleFor(clientId, user);
      chip.setAttribute('aria-label', chip.title);

      if (!isSelf) {
        chip.addEventListener('click', () => {
          // Following this editor: stop and return to idle so the next click
          // is a plain jump again.
          if (followingClientId === clientId) {
            followingClientId = null;
            jumpedClientId = null;
            render();
            return;
          }

          const pos = remoteCursorPos(editor, awareness.getStates().get(clientId));
          if (pos !== null) scrollToPos(editor, pos, 'smooth');

          // First click jumps, a second click on the same chip follows.
          if (jumpedClientId === clientId) {
            followingClientId = clientId;
          } else {
            followingClientId = null;
            jumpedClientId = clientId;
          }
          render();
        });
      }

      bar.appendChild(chip);
    });
  };

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
