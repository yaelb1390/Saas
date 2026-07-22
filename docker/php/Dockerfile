# BM Business OS — Imagen PHP 8.4-FPM para Laravel 12
FROM php:8.4-fpm-alpine

# Dependencias del sistema para compilar extensiones (+ su-exec para bajar privilegios)
RUN apk add --no-cache \
        bash \
        git \
        curl \
        su-exec \
        gzip \
        postgresql-client \
        libpng-dev \
        libzip-dev \
        icu-dev \
        postgresql-dev \
        oniguruma-dev \
        $PHPIZE_DEPS

# Extensiones PHP necesarias para el ERP (Postgres, colas, imágenes, i18n, dinero)
RUN docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        pcntl \
        intl \
        gd \
        zip \
        opcache

# Redis vía PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Composer (copiado desde la imagen oficial)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Config PHP como base
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-bmbusinessos.ini

# Pool php-fpm: workers como usuario 'app'
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-bmbusinessos.conf

# Entrypoint que corrige permisos del volumen montado y baja a usuario 'app'
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Usuario no-root para ejecutar la app (el entrypoint arranca como root y baja a este usuario)
RUN addgroup -g 1000 app && adduser -G app -u 1000 -s /bin/sh -D app

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
