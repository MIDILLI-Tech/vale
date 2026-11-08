#!/bin/sh
set -e

echo "Starting Vale Container Initialization..."

# 1. Auto-populate /var/www/html if it's empty or missing index.php (e.g., when bind-mounted or mapped to an empty volume)
if [ ! -f "/var/www/html/public/index.php" ]; then
    if [ -d "/usr/src/vale" ] && [ "$(ls -A /usr/src/vale 2>/dev/null)" ]; then
        echo "Empty or uninitialized volume detected in /var/www/html! Auto-populating codebase from backup..."
        cp -a /usr/src/vale/. /var/www/html/
    else
        echo "Warning: /var/www/html/public/index.php is missing and no backup source /usr/src/vale is available."
    fi
fi

# 2. Ensure all storage, themes, and public directories exist
echo "Ensuring storage, themes, and public directories exist..."
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/cache/pages \
         /var/www/html/storage/sessions \
         /var/www/html/storage/uploads \
         /var/www/html/storage/ratelimit \
         /var/www/html/storage/cache/container \
         /var/www/html/storage/backups \
         /var/www/html/storage/framework \
         /var/www/html/themes \
         /var/www/html/content/plugins \
         /var/www/html/content/mu-plugins

# Clear compiled DI container so it regenerates against the current source code.
# storage/cache is often a persistent volume: a stale CompiledContainer.php
# survives redeploys and breaks when constructor signatures change
# (PHP-DI only recompiles when config/services.php is newer than the cache).
rm -rf /var/www/html/storage/cache/container
mkdir -p /var/www/html/storage/cache/container

# Clear compiled Twig templates for the same reason: with auto_reload off in
# production, Twig never checks whether the .twig sources changed, so a stale
# compiled layout.twig from a persistent volume would keep serving old markup
# (e.g. pre-fix asset URLs) against freshly deployed code until the cache is
# explicitly cleared.
rm -rf /var/www/html/storage/cache/views
mkdir -p /var/www/html/storage/cache/views

# Set correct ownership for the writable paths only. The web server serves
# public/ read-only, uploads are served from storage/uploads via the web server
# alias (docker/vhost.conf, docker/nginx.conf), and themes are user content.
# A recursive chown over the whole tree would also walk a bind-mounted codebase
# on every restart — slow, and unnecessary for read-only files.
echo "Setting correct ownership and permissions..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/themes /var/www/html/content
chmod -R 775 /var/www/html/storage /var/www/html/themes /var/www/html/content

# 3. Run database migrations if database configuration is available
#    (either a non-empty .env file, or DB_* environment variables injected by CapRover/Docker).
#    After successful migration, create installed.lock so the web application can boot.
if { [ -s "/var/www/html/.env" ] || [ -n "$DB_HOST" ] || [ -n "$DB_DRIVER" ]; }; then
    echo "Database configuration detected. Running database migrations..."
    if [ -f "/var/www/html/vale" ] && [ -d "/var/www/html/vendor" ]; then
        if su -s /bin/sh -c "php /var/www/html/vale migrate --no-interaction" www-data; then
            # Create installed.lock ONLY after successful migrations, so the
            # web application never marks itself as installed against a
            # missing / failed schema.
            if [ ! -f "/var/www/html/storage/installed.lock" ]; then
                echo "$(date '+%Y-%m-%d %H:%M:%S')" > /var/www/html/storage/installed.lock
                chown www-data:www-data /var/www/html/storage/installed.lock
                echo "Created storage/installed.lock."
            fi
        else
            echo "Warning: Database migrations could not be run. Database might not be ready yet."
        fi
    else
        echo "vale CLI or vendor directory not found. Skipping auto-migration."
    fi
else
    echo "No database configuration found (.env empty and no DB_* env vars). Skipping auto-migration."
fi

# 4. Vale CLI passthrough — allows `docker run <image> vale <command>`
if [ "$1" = "vale" ]; then
    shift
    # Run as www-data, exactly like the migrations above and the web server, so
    # files the CLI writes (SQLite journals, backups, caches) stay writable for
    # the site. `docker exec <container> vale ...` goes through the wrapper in
    # /usr/local/bin/vale, which applies the same rule.
    if command -v setpriv >/dev/null 2>&1; then
        exec setpriv --reuid=www-data --regid=www-data --init-groups php /var/www/html/vale "$@"
    fi
    exec php /var/www/html/vale "$@"
fi

# 4.5 PHP limits (Docker-only; not read from .env / app settings).
# Defaults match the baked php.ini / nginx.conf / vhost.conf
# (128M upload, 512M memory, 120s). Override at runtime:
#   docker run -e UPLOAD_MAX_FILESIZE=256M -e POST_MAX_SIZE=256M \
#              -e MEMORY_LIMIT=1G -e MAX_EXECUTION_TIME=300 ...
UPLOAD_MAX_FILESIZE="${UPLOAD_MAX_FILESIZE:-128M}"
POST_MAX_SIZE="${POST_MAX_SIZE:-128M}"
MEMORY_LIMIT="${MEMORY_LIMIT:-512M}"
MAX_EXECUTION_TIME="${MAX_EXECUTION_TIME:-120}"
MAX_INPUT_TIME="${MAX_INPUT_TIME:-120}"
if [ -f /usr/local/etc/php/conf.d/vale-php.ini ]; then
    sed -i "s/^upload_max_filesize = .*/upload_max_filesize = ${UPLOAD_MAX_FILESIZE}/" /usr/local/etc/php/conf.d/vale-php.ini
    sed -i "s/^post_max_size = .*/post_max_size = ${POST_MAX_SIZE}/" /usr/local/etc/php/conf.d/vale-php.ini
    sed -i "s/^memory_limit = .*/memory_limit = ${MEMORY_LIMIT}/" /usr/local/etc/php/conf.d/vale-php.ini
    sed -i "s/^max_execution_time = .*/max_execution_time = ${MAX_EXECUTION_TIME}/" /usr/local/etc/php/conf.d/vale-php.ini
    sed -i "s/^max_input_time = .*/max_input_time = ${MAX_INPUT_TIME}/" /usr/local/etc/php/conf.d/vale-php.ini
fi
# Keep the web-server timeouts in step with PHP, otherwise Nginx/Apache cut the
# request before PHP's own max_execution_time is reached. 0 (unlimited) leaves
# the server timeouts at their baked defaults.
if [ "${MAX_EXECUTION_TIME}" != "0" ] && [ -f /etc/apache2/sites-available/000-default.conf ]; then
    sed -i "s/^    Timeout .*/    Timeout ${MAX_EXECUTION_TIME}/" /etc/apache2/sites-available/000-default.conf
fi
if [ -f /etc/nginx/conf.d/default.conf ]; then
    sed -i "s/client_max_body_size .*/client_max_body_size ${POST_MAX_SIZE};/" /etc/nginx/conf.d/default.conf
    if [ "${MAX_EXECUTION_TIME}" != "0" ]; then
        sed -i "s/fastcgi_send_timeout .*/fastcgi_send_timeout ${MAX_EXECUTION_TIME}s;/" /etc/nginx/conf.d/default.conf
        sed -i "s/fastcgi_read_timeout .*/fastcgi_read_timeout ${MAX_EXECUTION_TIME}s;/" /etc/nginx/conf.d/default.conf
    fi
fi

# 5. Start the appropriate web server
if [ "$1" = "apache2-foreground" ]; then
    echo "Starting Apache in foreground..."
    exec apache2-foreground
elif which apache2-foreground >/dev/null 2>&1; then
    echo "Starting Apache in foreground (auto-detected)..."
    exec apache2-foreground
elif which php-fpm >/dev/null 2>&1 && which nginx >/dev/null 2>&1; then
    echo "Starting PHP-FPM and Nginx (auto-detected)..."
    # Start PHP-FPM in background
    php-fpm -D
    # Start Nginx in foreground
    exec nginx -g 'daemon off;'
else
    echo "Running custom command: $@"
    exec "$@"
fi
