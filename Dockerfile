# Base images are pinned by digest (reproducible, scanned builds); the tag stays for Dependabot.
FROM dunglas/frankenphp:1-php8.5-bookworm@sha256:e673b1932022450620304b18543d9437205fb5ded3daf25fadf7d777fa4cd0b8 AS php-base

RUN install-php-extensions curl intl mbstring pcntl pdo_mysql xml opcache

FROM php-base AS application-builder

RUN apt-get update \
    && apt-get install --yes --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:919efe8e62179c71dfd31fad035fd671fe7454211c6b2aa30a10af0ab4acedba /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

COPY . ./

RUN touch .env

RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod APP_DEBUG=0 APP_SHARE_DIR=/tmp/zunaflux-share APP_SECRET=build-secret DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' MAILER_DSN='null://null' MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=async' ZUNAFLUX_PAGE_SIZE=30 ZUNAFLUX_MAX_SUBSCRIPTIONS=100 ZUNAFLUX_PUBLIC_REGISTRATION=0 ZUNAFLUX_MEDIA_STORAGE=var/media php bin/console tailwind:build --minify --env=prod \
    && APP_ENV=prod APP_DEBUG=0 APP_SHARE_DIR=/tmp/zunaflux-share APP_SECRET=build-secret DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' MAILER_DSN='null://null' MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=async' ZUNAFLUX_PAGE_SIZE=30 ZUNAFLUX_MAX_SUBSCRIPTIONS=100 ZUNAFLUX_PUBLIC_REGISTRATION=0 ZUNAFLUX_MEDIA_STORAGE=var/media php bin/console asset-map:compile --env=prod \
    && APP_ENV=prod APP_DEBUG=0 APP_SHARE_DIR=/tmp/zunaflux-share APP_SECRET=build-secret DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' MAILER_DSN='null://null' MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=async' ZUNAFLUX_PAGE_SIZE=30 ZUNAFLUX_MAX_SUBSCRIPTIONS=100 ZUNAFLUX_PUBLIC_REGISTRATION=0 ZUNAFLUX_MEDIA_STORAGE=var/media php bin/console cache:warmup --env=prod

FROM php-base AS runtime

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=Europe/Paris \
    APP_SHARE_DIR=/tmp/zunaflux-share \
    MAILER_DSN=null://null \
    MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async \
    ZUNAFLUX_PAGE_SIZE=30 \
    ZUNAFLUX_MAX_SUBSCRIPTIONS=100 \
    ZUNAFLUX_PUBLIC_REGISTRATION=0 \
    ZUNAFLUX_MEDIA_STORAGE=var/media

COPY docker/frankenphp/php/conf.d/zunaflux.ini /usr/local/etc/php/conf.d/zunaflux.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/collect-feeds.sh /usr/local/bin/collect-feeds
COPY docker/send-notifications.sh /usr/local/bin/send-notifications
# The code and the warmed cache belong to root: the application user cannot modify them.
COPY --from=application-builder /app /app

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p var/media /config/caddy /data/caddy \
    && chown -R www-data:www-data var/media /config/caddy /data/caddy \
    && chmod 0555 /usr/local/bin/collect-feeds /usr/local/bin/send-notifications

USER www-data

# The base image probes Caddy's admin API, which is disabled here. CLI services
# share this image; only the HTTP app gets a healthcheck in Compose.
HEALTHCHECK NONE

EXPOSE 8080

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
