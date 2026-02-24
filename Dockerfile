FROM php:8.3-apache

# Увімкнути модулі (rewrite часто потрібен)
RUN a2enmod rewrite headers

# DocumentRoot -> твій public
ENV APACHE_DOCUMENT_ROOT=/var/www/web-php/public

# Підмінити DocumentRoot в конфігах Apache
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www

# Копіюємо весь проект
COPY . /var/www

# (Опційно) права на папки, які пишуться
RUN chown -R www-data:www-data /var/www/web-php/storage /var/www/web-php/data 2>/dev/null || true

# Скрипт старту, щоб Apache слухав PORT від Railway
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["sh", "/usr/local/bin/start.sh"]