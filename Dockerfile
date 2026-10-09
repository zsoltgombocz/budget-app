# syntax=docker/dockerfile:1

# Base images come through Google's Docker Hub mirror: anonymous pulls from GitHub runners hit
# Docker Hub's rate limit (429) and broke builds.
FROM mirror.gcr.io/library/composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

FROM mirror.gcr.io/oven/bun:1 AS assets
WORKDIR /app
COPY package.json bun.lock bunfig.toml ./
RUN bun install --frozen-lockfile
COPY vite.config.js ./
COPY resources/ resources/
COPY public/ public/
# app.css pulls Flux's stylesheet and scans framework views from vendor/.
COPY --from=vendor /app/vendor/livewire/flux/ vendor/livewire/flux/
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views/ vendor/laravel/framework/src/Illuminate/Pagination/resources/views/
RUN bun run build

FROM mirror.gcr.io/library/php:8.4-fpm AS app

# Commit the image was built from; Sentry groups errors by it.
ARG APP_REVISION=""
ENV SENTRY_RELEASE=${APP_REVISION}

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libgmp-dev \
        rsync \
        unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip pcntl gmp opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=mirror.gcr.io/library/composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi

# public/ is a shared-volume mount point at runtime so the Caddy container can serve
# static files directly. Keep the built copy at public-image/ and re-seed the volume
# on every start (named volumes are only auto-populated on first creation).
RUN cp -r public public-image \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public-image

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
