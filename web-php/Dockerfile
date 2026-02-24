FROM php:8.3-cli

WORKDIR /app
COPY . /app

# Логи помилок у консоль
RUN printf '%s\n' \
'display_errors=1' \
'display_startup_errors=1' \
'error_reporting=E_ALL' \
'log_errors=1' \
'error_log=/proc/self/fd/2' \
> /usr/local/etc/php/conf.d/railway-errors.ini

EXPOSE 8080

CMD ["sh","-lc","cd /app/web-php/public && echo \"PHP server on :8080\" && php -S 0.0.0.0:8080 router.php"]