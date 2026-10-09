FROM composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer-bin
FROM php:8.4.19-fpm-bookworm@sha256:7b0f2acae67bd1678a12abcb359023c4e4fd168a87f4704f5a75c96f0a5406d5 AS base
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip libpng-dev libjpeg62-turbo-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql intl zip opcache pcntl gd \
 && rm -rf /var/lib/apt/lists/*
COPY docker/php.ini /usr/local/etc/php/conf.d/portal.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-portal.conf
ENV FPM_MAX_CHILDREN=16 FPM_START_SERVERS=4 FPM_MIN_SPARE_SERVERS=2 FPM_MAX_SPARE_SERVERS=6
WORKDIR /var/www/html
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
FROM base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress
FROM base AS runtime
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN composer dump-autoload --no-dev --optimize && chown -R www-data:www-data storage bootstrap/cache && chmod 755 docker/entrypoint.sh
USER www-data
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
# QA only: production PHP (incl. GD) for running PHPUnit with the project bind-mounted at /app.
FROM base AS test
WORKDIR /app
CMD ["vendor/bin/phpunit"]
FROM nginx:1.28.2-alpine@sha256:5b4900b042ccfa8b0a73df622c3a60f2322faeb2be800cbee5aa7b44d241649e AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
