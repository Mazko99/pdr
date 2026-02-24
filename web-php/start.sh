#!/bin/sh
set -e

: "${PORT:=8080}"

# Apache за замовчуванням слухає 80, а Railway дає свій $PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf

# У деяких образах ще є VirtualHost :80
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

exec apache2-foreground