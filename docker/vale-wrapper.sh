#!/bin/sh
# Vale CLI wrapper — lets you run `vale <command>` inside a container
# instead of `php vale <command>`. Only ships with the Docker images.
set -e

# CLI commands write into the persistent storage volume (SQLite journals, caches,
# logs, backups). The entrypoint runs its migrations as www-data and the web server
# runs as www-data too, so the CLI must use the same user: a root-owned file (for
# example a SQLite -wal/-shm journal) leaves the site unable to write and turns the
# database read-only. `setpriv` keeps argument passing byte-exact (passwords with
# spaces or quotes survive); without it, fall back to running as root.
if [ "$(id -u)" = "0" ] && command -v setpriv >/dev/null 2>&1; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups php /var/www/html/vale "$@"
fi

exec php /var/www/html/vale "$@"
