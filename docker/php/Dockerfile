# ─── Stage 1: Composer ────────────────────────────────────────────────────────
# Pull composer binary from official image — clean, no extra deps in final image.
FROM composer:2 AS composer-bin

# ─── Stage 2: Application ─────────────────────────────────────────────────────
# php:8.2-fpm-alpine — minimal base, works on AMD64 (Linux) and ARM64 (Mac M1/M2/M3)
FROM php:8.2-fpm-alpine3.18

# ─── Build Arguments ──────────────────────────────────────────────────────────
# APP_UID/GID: must match the host user on Linux to avoid volume permission issues.
# On Mac (Docker Desktop), UID mapping is handled automatically — these are ignored.
ARG APP_USER=appuser
ARG APP_UID=1000
ARG APP_GID=1000

# ─── System Packages + PHP Extensions ────────────────────────────────────────
# Single RUN layer: install → build extensions → remove build tools
# Final image contains NO compilers or dev headers.
RUN apk add --no-cache \
        bash \
        icu-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
    # pdo_mysql : MySQL connectivity (Doctrine DBAL)
    # intl      : Locale-aware formatting (Money display)
    # opcache   : Bytecode cache (production performance)
    # bcmath    : Arbitrary precision (safety net for calculations)
    # pcntl     : Process control signals (future async workers)
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        intl \
        opcache \
        bcmath \
        pcntl \
    # redis extension — pinned version for reproducible builds
    && pecl install redis-6.0.2 \
    && docker-php-ext-enable redis \
    # Remove compile-time deps from final image
    && apk del .build-deps \
    # ── Non-root user ────────────────────────────────────────────────────────
    # FPM master runs as root (required for port binding + setuid to workers).
    # ALL PHP code execution happens in worker processes owned by appuser.
    && addgroup -g ${APP_GID} ${APP_USER} \
    && adduser -D -u ${APP_UID} -G ${APP_USER} ${APP_USER}

# ─── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

# ─── Configuration ────────────────────────────────────────────────────────────
COPY php.ini  /usr/local/etc/php/conf.d/99-app.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# FPM listens on TCP 9000 — only reachable within the fund_internal Docker network
EXPOSE 9000
