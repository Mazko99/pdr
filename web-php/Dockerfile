FROM php:8.3-fpm

# Install Apache + modules for PHP-FPM proxy
RUN apt-get update \
 && apt-get install -y apache2 libapache2-mod-fcgid \
 && a2enmod rewrite proxy proxy_fcgi setenvif headers \
 && rm -rf /var/lib/apt/lists/*

# Set DocumentRoot
ENV APP_DOCROOT=/var/www/web-php/public

WORKDIR /var/www
COPY . /var/www

# Apache vhost template with placeholder
RUN printf '%s\n' \
'<VirtualHost *:__PORT__>' \
'    ServerName localhost' \
'    DocumentRoot '"${APP_DOCROOT}" \
'    <Directory '"${APP_DOCROOT}"'>' \
'        AllowOverride All' \
'        Require all granted' \
'    </Directory>' \
'' \
'    # Send .php to PHP-FPM' \
'    <FilesMatch \.php$>' \
'        SetHandler "proxy:fcgi://127.0.0.1:9000"' \
'    </FilesMatch>' \
'' \
'    ErrorLog /proc/self/fd/2' \
'    CustomLog /proc/self/fd/1 combined' \
'</VirtualHost>' \
> /etc/apache2/sites-available/000-default.conf

# Make Apache default port placeholder in ports.conf
RUN sed -i 's/Listen 80/Listen __PORT__/' /etc/apache2/ports.conf

# Permissions (optional but useful)
RUN chown -R www-data:www-data /var/www

# Start script: replace __PORT__ with Railway $PORT, start php-fpm and apache
RUN printf '%s\n' \
'#!/bin/sh' \
'set -e' \
': "${PORT:=8080}"' \
'' \
'# Replace placeholders with real PORT' \
'sed -i "s/__PORT__/${PORT}/g" /etc/apache2/ports.conf' \
'sed -i "s/__PORT__/${PORT}/g" /etc/apache2/sites-available/000-default.conf' \
'' \
'# Start PHP-FPM in background' \
'php-fpm -D' \
'' \
'# Start Apache in foreground' \
'exec apachectl -D FOREGROUND' \
> /start.sh \
&& chmod +x /start.sh

CMD ["/start.sh"]