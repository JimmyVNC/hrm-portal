FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install opcache zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    mkdir -p /var/www/html/runtime/cache && \
    chown -R www-data:www-data /var/www/html/runtime

EXPOSE 80
