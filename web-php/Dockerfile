FROM php:8.3-cli

WORKDIR /var/www

COPY . /var/www

# optional extensions
RUN docker-php-ext-install opcache

# permissions
RUN chown -R www-data:www-data /var/www

# Railway start
CMD sh -c "php -S 0.0.0.0:${PORT:-8080} -t web-php/public"