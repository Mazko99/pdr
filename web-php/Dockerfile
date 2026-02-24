FROM php:8.2-cli

WORKDIR /app

# копіюємо весь проект (вміст web-php) в контейнер
COPY . /app

# Railway дає PORT змінну. Ми слухаємо її.
CMD ["sh", "-lc", "php -S 0.0.0.0:${PORT:-8080} -t public"]