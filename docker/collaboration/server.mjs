// Minimal self-hosted Hocuspocus (Yjs WebSocket) server for Vale editor
// collaboration. Docs: https://tiptap.dev/docs/hocuspocus
import { Server } from '@hocuspocus/server';

const port = Number(process.env.PORT || 1234);

// Accepted tokens. COLLAB_SECRET keeps the simple single-token setup;
// COLLAB_SECRETS takes a comma-separated list, optionally binding a token to a
// tenant prefix: "site-a:tokenA,site-b:tokenB". A tenant token may only open
// rooms whose name starts with "<tenant>/", which is what the admin setting
// "Room prefix" produces.
const tokens = new Map();
if (process.env.COLLAB_SECRET) {
  tokens.set(process.env.COLLAB_SECRET, '');
}
for (const entry of String(process.env.COLLAB_SECRETS || '').split(',')) {
  const value = entry.trim();
  if (value === '') continue;
  const separator = value.indexOf(':');
  if (separator === -1) {
    tokens.set(value, '');
    continue;
  }
  const tenant = value.slice(0, separator).trim();
  const token = value.slice(separator + 1).trim();
  if (tenant !== '' && token !== '') tokens.set(token, tenant);
}

// One line per event, docker logs friendly. Tokens are never logged.
const log = (...parts) => console.log(new Date().toISOString(), '[vale-collaboration]', ...parts);

// documentName -> Set of connected socket ids, so the log can report how many
// editors are currently in a room. Room names are `[<prefix>/]<path>/<id>/edit`.
const rooms = new Map();

const server = new Server({
  port,
  async onAuthenticate({ token, documentName }) {
    const provided = String(token || '');

    // No token configured at all: open server, local trial only.
    if (tokens.size === 0) {
      log('auth', 'accepted (no token configured)', `room=${documentName}`);
      return;
    }

    const tenant = tokens.get(provided);
    if (tenant === undefined) {
      log('auth', 'rejected (unknown token)', `room=${documentName}`);
      throw new Error('Unauthorized');
    }

    if (tenant !== '' && !String(documentName).startsWith(`${tenant}/`)) {
      log('auth', 'rejected (room outside tenant)', `tenant=${tenant}`, `room=${documentName}`);
      throw new Error('Forbidden room');
    }

    log('auth', 'accepted', tenant === '' ? 'tenant=*' : `tenant=${tenant}`, `room=${documentName}`);
  },
  onConnect({ documentName, socketId }) {
    const sockets = rooms.get(documentName) || new Set();
    sockets.add(socketId);
    rooms.set(documentName, sockets);
    log('connect', `room=${documentName}`, `editors=${sockets.size}`);
  },
  onDisconnect({ documentName, socketId }) {
    const sockets = rooms.get(documentName);
    if (!sockets) {
      log('disconnect', `room=${documentName}`, 'editors=0');
      return;
    }
    sockets.delete(socketId);
    if (sockets.size === 0) {
      rooms.delete(documentName);
      log('disconnect', `room=${documentName}`, 'editors=0', 'room closed');
      return;
    }
    log('disconnect', `room=${documentName}`, `editors=${sockets.size}`);
  },
});

await server.listen();

const tenants = [...new Set([...tokens.values()].filter((tenant) => tenant !== ''))];
log(
  `Hocuspocus listening on ws://0.0.0.0:${port}` +
    (tokens.size === 0
      ? ' (no token required)'
      : ` (${tokens.size} token(s)${tenants.length > 0 ? `, tenants: ${tenants.join(', ')}` : ''})`),
);
