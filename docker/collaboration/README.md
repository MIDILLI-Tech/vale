# Vale collaboration server

Self-hosted [Hocuspocus](https://tiptap.dev/docs/hocuspocus) — the Yjs WebSocket
backend Tiptap ships for collaboration. It is a plain Node process; documents
live in memory, so this image is meant for a local Docker Desktop test.

## Build and run (Docker Desktop)

```bash
docker build -t vale-collaboration docker/collaboration

docker run --rm -p 1234:1234 -e COLLAB_SECRET=dev-secret vale-collaboration
```

The server logs `Hocuspocus listening on ws://0.0.0.0:1234`.

## Admin settings

**Settings → Content → Collaboration**:

| Field | Meaning |
|-------|---------|
| Server | Server catalog entry (`private` today) |
| Server URL | `ws://localhost:1234` locally, `wss://collab.example.com` behind HTTPS |
| Room prefix | Namespace for this installation's rooms; must equal the tenant when the token is tenant-bound |
| Shared secret | Must be accepted by the server: `COLLAB_SECRET` or one entry of `COLLAB_SECRETS`; leave empty on both sides to run open |

## Multiple installations (tokens and tenants)

`COLLAB_SECRETS` takes a comma-separated list. Prefix an entry with
`tenant:` to bind that token to a room namespace:

```bash
docker run --rm -p 1234:1234 \
  -e COLLAB_SECRETS='site-a:tokenA,site-b:tokenB' \
  vale-collaboration
```

- `site-a` uses secret `tokenA` and **Room prefix** `site-a` → rooms
  `site-a/admin/pages/5/edit`. The same token cannot open `site-b/...` or an
  unprefixed room.
- `site-b` uses `tokenB` + prefix `site-b`.
- A plain entry without `:` (`COLLAB_SECRETS='shared-token'`) is accepted for
  any room, like `COLLAB_SECRET`.
- `COLLAB_SECRET` still works and is merged with the list, so existing setups
  keep running while you migrate.

There is no password hashing or per-user account: a token identifies one
installation, and the room prefix keeps its documents apart. Use TLS
(`wss://`) whenever the server is not on localhost.

The dashboard shows a notice when collaboration is enabled but the URL is
missing or the address does not accept connections (TCP probe, cached 5 min).

## Logs

Every auth, connect and disconnect is printed to stdout with a timestamp, so
`docker logs vale-collaboration` shows who joined which room:

```text
... [vale-collaboration] auth accepted room=admin/pages/5/edit
... [vale-collaboration] connect room=admin/pages/5/edit editors=1
... [vale-collaboration] disconnect room=admin/pages/5/edit editors=0 room closed
```

Tokens are never logged. Nothing is stored on disk.

## Room prefix

Settings → Content → Collaboration → **Room prefix** prepends a namespace to
every room name (`site-a/admin/pages/5/edit`), so several Vale installations
can share one server.

## Notes

- **No persistence:** restarting the container drops open documents. Add
  `@hocuspocus/extension-database` (or Redis) when this moves beyond a test.
- **TLS:** terminate HTTPS in front of the server and use `wss://`; a `ws://`
  URL on an HTTPS admin page is blocked by the browser.
- **Client:** `resources/js/editor/collaboration.js` opens the room for saved
  records (`…/<id>/edit`) and `@hocuspocus/provider` + `yjs` are bundled into
  the editor build. The saved HTML is still written by the normal form save.
