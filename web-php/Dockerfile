FROM php:8.3-apache

# Увімкнути потрібні модулі Apache
RUN a2enmod rewrite headers

# Fix: Apache MPM conflict (залишаємо тільки prefork для mod_php)
RUN a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork

# Встановити DocumentRoot на твій public
ENV APACHE_DOCUMENT_ROOT=/var/www/web-php/public

# Замінити DocumentRoot у конфігах Apache
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Робоча папка
WORKDIR /var/www

# Копіювати весь проект
COPY . /var/www

# Права доступу для Railway (важливо для storage і data)
RUN chown -R www-data:www-data /var/www \
 && chmod -R 755 /var/www \
 && chmod -R 775 /var/www/web-php/storage 2>/dev/null || true \
 && chmod -R 775 /var/www/web-php/data 2>/dev/null || true

# Створити startup script для Railway PORT
RUN echo '#!/bin/sh' > /start.sh \
 && echo 'set -e' >> /start.sh \
 && echo ': "${PORT:=8080}"' >> /start.sh \
 && echo 'sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf' >> /start.sh \
 && echo 'sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true' >> /start.sh \
 && echo 'exec apache2-foreground' >> /start.sh \
 && chmod +x /start.sh

# Запуск Apache
CMD ["/start.sh"]