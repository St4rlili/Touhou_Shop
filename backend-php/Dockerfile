FROM php:8.2-cli-alpine
# Herramientas de compilación y dependencias para MongoDB
RUN apk add --no-cache $PHPIZE_DEPS openssl-dev icu-dev
# Compila y activar la extensión oficial de MongoDB
RUN pecl install mongodb && docker-php-ext-enable mongodb
# Instala Composer (Gestor de dependencias de PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000"]
