#!/bin/sh
# BM Business OS — Entrypoint
# Corrige la propiedad de los directorios escribibles (necesario con bind mounts en
# Windows/macOS, donde el volumen se monta como root) y ejecuta el comando adecuado.
#
#  - php-fpm: el MASTER corre como root (para abrir logs/sockets) y los WORKERS como
#    'app' según la config del pool (docker/php/www.conf).
#  - artisan / queue / otros comandos CLI: se ejecutan directamente como 'app'.
set -e

if [ "$(id -u)" = "0" ]; then
    chown -R app:app storage bootstrap/cache 2>/dev/null || true

    if [ "$1" = "php-fpm" ]; then
        exec "$@"
    fi

    exec su-exec app "$@"
fi

exec "$@"
