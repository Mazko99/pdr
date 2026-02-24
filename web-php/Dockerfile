FROM php:8.3-cli

WORKDIR /app
COPY . /app

RUN printf '%s\n' \
'display_errors=1' \
'display_startup_errors=1' \
'error_reporting=E_ALL' \
'log_errors=1' \
'error_log=/proc/self/fd/2' \
> /usr/local/etc/php/conf.d/railway-errors.ini

CMD ["sh","-lc","cd /app/web-php/public && php -S 0.0.0.0:${PORT} router.php"]