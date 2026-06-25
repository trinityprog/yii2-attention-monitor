FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
        libsqlite3-dev \
        libonig-dev \
    && docker-php-ext-install pdo_sqlite mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
# Full install (incl. require-dev): config/web.php bootstraps the Gii/debug
# modules whenever YII_ENV=dev, and those packages live in require-dev.
RUN composer install --no-interaction --prefer-dist \
    && mkdir -p runtime web/assets \
    && chmod -R 0777 runtime web/assets

RUN chmod +x docker/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["docker/entrypoint.sh"]
