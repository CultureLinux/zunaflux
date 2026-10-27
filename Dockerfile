FROM php:8.5-fpm-bookworm AS extension-builder

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring pdo_mysql xml \
    && rm -rf /var/lib/apt/lists/*

FROM extension-builder AS application-builder

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

COPY . ./

RUN touch .env

RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-secret DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' MAILER_DSN='null://null' MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=async' ZUNAFLUX_PAGE_SIZE=30 ZUNAFLUX_MAX_SUBSCRIPTIONS=100 ZUNAFLUX_PUBLIC_REGISTRATION=0 ZUNAFLUX_MEDIA_STORAGE=var/media php bin/console tailwind:build --minify --env=prod \
    && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-secret DATABASE_URL='mysql://zunaflux:change-me@database:3306/zunaflux?serverVersion=mariadb-10.11.0&charset=utf8mb4' MAILER_DSN='null://null' MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=async' ZUNAFLUX_PAGE_SIZE=30 ZUNAFLUX_MAX_SUBSCRIPTIONS=100 ZUNAFLUX_PUBLIC_REGISTRATION=0 ZUNAFLUX_MEDIA_STORAGE=var/media php bin/console asset-map:compile --env=prod

FROM php:8.5-fpm-bookworm AS runtime

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_TIMEZONE=Europe/Paris \
    MAILER_DSN=null://null \
    MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async \
    ZUNAFLUX_PAGE_SIZE=30 \
    ZUNAFLUX_MAX_SUBSCRIPTIONS=100 \
    ZUNAFLUX_PUBLIC_REGISTRATION=0 \
    ZUNAFLUX_MEDIA_STORAGE=var/media

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        libcurl4 \
        libicu72 \
        libonig5 \
        libxml2 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=extension-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extension-builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY docker/php/conf.d/zunaflux.ini /usr/local/etc/php/conf.d/zunaflux.ini
COPY docker/collect-feeds.sh /usr/local/bin/collect-feeds
COPY --from=application-builder --chown=www-data:www-data /app /app

WORKDIR /app

RUN mkdir -p var/cache var/log var/media \
    && chown -R www-data:www-data var \
    && chmod 0555 /usr/local/bin/collect-feeds

USER www-data

CMD ["php-fpm"]
