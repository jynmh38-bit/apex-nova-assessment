FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev libxml2-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_sqlite mbstring dom xml xmlwriter zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress
ENV DB_CONNECTION=sqlite DB_DATABASE=/app/database/data/inventory.sqlite APP_DEBUG=false
EXPOSE 8000
ENTRYPOINT ["sh", "docker-entrypoint.sh"]
