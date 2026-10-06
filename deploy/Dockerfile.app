FROM php:8.2-fpm-alpine

RUN apk add --no-cache libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev oniguruma-dev icu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql mbstring gd zip opcache

COPY api/ /var/www/html/api/

WORKDIR /var/www/html/api
