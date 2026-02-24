FROM php:8.3-cli

WORKDIR /app
COPY . /app

# Для стабільності: явно показуємо помилки в stdout
RUN printf '%s\n' \
'display_errors=1' \
'display_startup_errors=1' \
'error_reporting=E_ALL' \
'log_errors=1' \
'error_log=/proc/self/fd/2' \
> /usr/local/etc/php/conf.d/railway-errors.ini

# Стартуємо PHP в правильній папці + лог у stdout
CMD ["sh", "-lc", "echo \"Starting PHP server on 0.0.0.0:${PORT:-8080}\"; cd /app/web-php/public && php -S 0.0.0.0:${PORT:-8080} index.php"]