# FrankenPHP en modo clasico (no worker): un proceso PHP por peticion.
# Ver ADR 02 (FrankenPHP sobre php-fpm + Nginx).
FROM dunglas/frankenphp:1-php8.4

# Composer desde su imagen oficial.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Extensiones PHP necesarias (PostgreSQL, intl, opcache, zip para composer).
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip

WORKDIR /app

# Config de FrankenPHP/Caddy (timeouts >= 180s) y de PHP (max_execution_time).
COPY frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY frankenphp/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

# Codigo de la aplicacion (incluye composer.lock versionado -> build reproducible).
COPY . .

# Instala dependencias desde el lock. --no-scripts evita ejecutar recetas de
# Flex durante el build de imagen.
RUN composer install --no-interaction --optimize-autoloader --no-scripts \
    && composer dump-autoload --optimize

# El servidor escucha en :80 dentro del contenedor (SERVER_NAME se fija en compose).
ENV SERVER_NAME=:80

EXPOSE 80
