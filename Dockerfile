# Imagen del backend para Railway: PHP 8.4 + FrankenPHP (servidor web incluido)
FROM dunglas/frankenphp:1-php8.4

# Extensiones que usa el sistema:
# pdo_mysql (base de datos), gd y zip (PDF y Excel), intl, bcmath, exif, opcache
# calendar: easter_date() para los feriados de Semana Santa (vacaciones)
RUN install-php-extensions pdo_mysql gd zip intl bcmath exif opcache calendar

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencias primero, para que Docker reutilice esta capa si composer.lock no cambia
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
               storage/logs storage/app/public bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start
RUN sed -i 's/\r$//' /usr/local/bin/start && chmod +x /usr/local/bin/start

CMD ["start"]
