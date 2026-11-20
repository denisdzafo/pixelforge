FROM php:8.4-fpm-bookworm

# The container's user takes your host uid/gid, so files created in the bind mount (vendor, logs, uploads) belong to you
ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install bcmath intl opcache pcntl pdo_mysql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --2

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN groupadd --force --gid "$WWWGROUP" app \
    && useradd --create-home --shell /bin/bash --no-user-group --gid "$WWWGROUP" --uid "$WWWUSER" app

WORKDIR /var/www/html
USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
