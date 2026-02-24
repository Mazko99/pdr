FROM php:8.2-cli

WORKDIR /app

# копіюємо тільки web-php в контейнер
COPY web-php/ /app/

# відкриваємо порт Railway
EXPOSE 8080

# запускаємо PHP сервер
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public"]