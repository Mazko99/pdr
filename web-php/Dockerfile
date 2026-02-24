FROM php:8.3-apache

# Apache modules
RUN a2enmod rewrite headers

# HARD FIX: allow only ONE MPM (prefork) — remove others from mods-enabled
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
 && a2enmod mpm_prefork 2>/dev/null || true \
 && rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
 && rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
 && rm -f /etc/apache2/mods-enabled/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.conf \
 && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
 && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# DocumentRoot -> твій public
ENV APACHE_DOCUMENT_ROOT=/var/www/web-php/public

# Replace DocumentRoot in Apache configs
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www
COPY . /var/www

# Permissions (optional)
RUN chown -R www-data:www-data /var/www \
 && chmod -R 755 /var/www \
 && chmod -R 775 /var/www/web-php/storage 2>/dev/null || true \
 && chmod -R 775 /var/www/web-php/data 2>/dev/null || true

# Start script (Railway PORT)
RUN printf '%s\n' \
'#!/bin/sh' \
'set -e' \
': "${PORT:=8080}"' \
'sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf' \
'sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true' \
'exec apache2-foreground' \
> /start.sh && chmod +x /start.sh

CMD ["/start.sh"]