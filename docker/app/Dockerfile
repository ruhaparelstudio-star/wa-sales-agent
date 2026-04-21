FROM node:20-alpine AS node_builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-fpm

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git curl unzip libpng-dev libonig-dev libxml2-dev libzip-dev \
    libpq-dev libicu-dev libfreetype6-dev libjpeg62-turbo-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/app/php-local.ini /usr/local/etc/php/conf.d/99-local.ini
COPY docker/app/www-overrides.conf /usr/local/etc/php-fpm.d/zz-www-overrides.conf

COPY . /var/www
COPY --from=node_builder /app/public/build /var/www/public/build

RUN mkdir -p /var/www/bootstrap/cache /var/www/storage/framework/cache \
        /var/www/storage/framework/sessions /var/www/storage/framework/views \
        /var/www/storage/logs /var/www/storage/app/public \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
