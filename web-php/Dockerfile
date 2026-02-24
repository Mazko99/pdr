FROM php:8.3-fpm

# Install Apache and required modules
RUN apt-get update \
 && apt-get install -y apache2 libapache2-mod-fcgid \
 && a2enmod rewrite proxy proxy_fcgi setenvif \
 && rm -rf /var/lib/apt/lists/*

# Configure Apache to use PHP-FPM
RUN echo '<VirtualHost *:8080>\n\
    DocumentRoot /var/www/web-php/public\n\
    <Directory /var/www/web-php/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <FilesMatch \.php$>\n\
        SetHandler "proxy:fcgi://127.0.0.1:9000"\n\
    </FilesMatch>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Change Apache port to Railway PORT
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

WORKDIR /var/www
COPY . /var/www

RUN chown -R www-data:www-data /var/www

# Start script
RUN printf '%s\n' \
'#!/bin/sh' \
'set -e' \
': "${PORT:=8080}"' \
'sed -i "s/Listen 8080/Listen ${PORT}/" /etc/apache2/ports.conf' \
'php-fpm -D' \
'exec apachectl -D FOREGROUND' \
> /start.sh && chmod +x /start.sh

CMD ["/start.sh"]