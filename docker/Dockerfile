# ─── Vale — single parameterized image build ────────────────────────────────
# Replaces the four former files:
#   docker/apache.Dockerfile, docker/nginx.Dockerfile,
#   docker/immutable/apache.Dockerfile, docker/immutable/nginx.Dockerfile.
#
# Build arguments:
#   VARIANT=apache|nginx      Web server flavor (default: apache)
#   FLAVOR=immutable|mutable  Deployment flavor (default: immutable)
#
# scripts/publish-docker.php passes both via --build-arg; docker compose
# services set them through build.args. Tag matrix is documented in the
# vale-docs deployment guide.

ARG VARIANT=apache
ARG FLAVOR=immutable

# ─── Stage 1: Build front-end assets ────────────────────────────────────────
FROM node:26-alpine AS assets-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ─── Stage 2: Install PHP dependencies ──────────────────────────────────────
FROM composer:latest AS vendor-builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ─── Stage 3: Web server base — selected by VARIANT ─────────────────────────
# The Apache stage carries the default command; the Nginx stage relies on the
# shared entrypoint's auto-detection (php-fpm + nginx) when no command is set.
# PHP 8.4 — composer requires ^8.3, so 8.3 stays a valid host choice; the
# shipped images track the actively supported minor.
FROM php:8.5-apache AS base-apache
CMD ["apache2-foreground"]

FROM php:8.5-fpm AS base-nginx

# ─── Stage 4: Final production image ────────────────────────────────────────
FROM base-${VARIANT} AS runtime
# Re-declare so the arguments are usable by RUN logic in this stage.
ARG VARIANT
ARG FLAVOR
ENV VL_VARIANT=${VARIANT} \
    VL_FLAVOR=${FLAVOR}

# OS upgrades, PHP extensions (GD with WebP + AVIF), and the same-layer purge
# of build-only packages: -dev headers never land in the final image, and the
# pecl/(gcc|make|binutils|patch|m4|re2c) toolchain is purged too — those are
# the top Docker Scout Low-severity signals once extensions are compiled.
# Runtime libraries the compiled extensions link against are protected by the
# apt-mark manual pass below.
RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y; \
    if [ "$VARIANT" = "nginx" ]; then NGINX="nginx"; else NGINX=""; fi; \
    apt-get install -y --no-install-recommends \
        $NGINX \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libavif-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libsodium-dev \
        libcurl4-openssl-dev \
        libpq-dev \
        unzip \
        curl \
        bash-completion; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif; \
    # The php:8.5 base prebuilds opcache (bundled into PHP core since 8.5)
    # and dom plus the libxml/lexbor HTML5 stack; reinstalling either via
    # docker-php-ext-install fails (opcache: empty modules/ dir, dom: missing
    # lexbor headers). Rely on the prebuilt ones.
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        sodium \
        curl; \
    for so in /usr/local/lib/php/extensions/*/*.so; do \
        ldd "$so"; \
    done | awk '/=> \// { print $3 }' | sort -u | while read -r lib; do \
        pkg=$(dpkg -S "$(readlink -f "$lib")" 2>/dev/null | cut -d: -f1); \
        [ -n "$pkg" ] && apt-mark manual "$pkg" 2>/dev/null || true; \
    done; \
    apt-get purge -y --auto-remove \
        gcc g++ make binutils patch m4 re2c \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libavif-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libsodium-dev \
        libcurl4-openssl-dev \
        libpq-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear ~/.pearrc

# Configure bash completion for the Vale CLI
RUN echo 'if [ -f /usr/share/bash-completion/bash_completion ]; then . /usr/share/bash-completion/bash_completion; fi' >> /etc/bash.bashrc \
    && echo 'if command -v vale >/dev/null 2>&1; then eval "$(vale completion bash 2>/dev/null)"; fi' >> /etc/bash.bashrc \
    && mkdir -p /etc/profile.d \
    && echo 'if [ -n "$BASH_VERSION" ] && command -v vale >/dev/null 2>&1; then eval "$(vale completion bash 2>/dev/null)"; fi' > /etc/profile.d/vale_completion.sh

WORKDIR /var/www/html

# Copy source first, then overlay production vendor and built assets
COPY . .
COPY --from=vendor-builder /app/vendor ./vendor
COPY --from=assets-builder /app/public/assets/build ./public/assets/build

# Dev-file cleanup + storage layout + permissions. FLAVOR differences:
#   mutable : copy the finished tree to /usr/src/vale — the entrypoint uses
#             it to auto-populate a fresh bind-mount / volume.
#   immutable: touch the .baked marker (the auto-updater treats it as a no-op).
RUN set -eux; \
    rm -rf node_modules tests scripts .git .github docker \
           package.json package-lock.json postcss.config.js tailwind.config.js .gitleaks.toml; \
    mkdir -p storage/cache/pages storage/sessions storage/cache/container storage/backups storage/framework \
             content/plugins content/mu-plugins; \
    touch /var/www/html/.env; \
    if [ "$FLAVOR" = "mutable" ]; then \
        mkdir -p /usr/src/vale; \
        cp -a /var/www/html/. /usr/src/vale/; \
    else \
        touch storage/.baked; \
    fi; \
    chown -R www-data:www-data /var/www/html; \
    chmod -R 775 /var/www/html/storage /var/www/html/public; \
    chmod 775 /var/www/html

# OPCache configuration
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Container PHP configuration (variables_order=EGPCS so $_ENV sees real env vars)
COPY docker/php.ini /usr/local/etc/php/conf.d/vale-php.ini

# Web-server configuration — both files stage to neutral paths, then the RUN
# below moves the right one in place (COPY itself cannot branch). The loser
# is removed.
COPY docker/vhost.conf /srv/vale-apache-vhost.conf
COPY docker/nginx.conf /srv/vale-nginx.conf

RUN set -eux; \
    if [ "$VARIANT" = "apache" ]; then \
        a2enmod rewrite headers expires; \
        mv /srv/vale-apache-vhost.conf /etc/apache2/sites-available/000-default.conf; \
        rm -f /srv/vale-nginx.conf; \
    else \
        mv /srv/vale-nginx.conf /etc/nginx/conf.d/default.conf; \
        mkdir -p /etc/nginx/sites-available && cp /etc/nginx/conf.d/default.conf /etc/nginx/sites-available/default; \
        rm -f /srv/vale-apache-vhost.conf; \
    fi; \
    rmdir /srv

EXPOSE 80

# Unified entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Vale CLI wrapper — `vale <command>` instead of `php vale <command>`
COPY docker/vale-wrapper.sh /usr/local/bin/vale
RUN chmod +x /usr/local/bin/vale

# Container health (unauthenticated endpoint)
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/api/v1/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
