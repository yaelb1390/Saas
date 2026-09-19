#!/bin/bash
# Corrige la config de la función y despliega el output que dejó build.sh con `--prebuilt`.
# Lo lanza run.ps1 dentro de un contenedor; ver docs/DEPLOY_VERCEL_PREBUILT.md.
#
# Montajes: /scripts (ro), /vc-src (ro, sesión del CLI), /data (volumen).
# Variables: PROD=1 despliega a producción (por defecto, preview); SESS=cookie|array (solo preview);
# BRIDGE_DEBUG=1 añade un registro de la forma de cada petición al launcher puente.
VC=/data/tools/node_modules/.bin/vercel

rm -rf /root/vcg && cp -r /vc-src /root/vcg
GC="--global-config /root/vcg"

cd /data/work || exit 1
F=$(find .vercel/output/functions -name .vc-config.json | head -1)
[ -z "$F" ] && { echo "no hay output: corre build antes"; exit 1; }

if [ "${BRIDGE_DEBUG:-0}" = "1" ]; then DBG="--debug"; else DBG=""; fi
node /scripts/patch-config.js "$F" $DBG || exit 1

# En un deploy prebuilt, .vercelignore choca con los ficheros que la función SÍ referencia (vendor/,
# storage/framework/cache/.gitignore...) y la nube falla con ENOENT al no encontrarlos.
rm -f .vercelignore

if [ "${PROD:-0}" = "1" ]; then
  # Producción: el runtime toma las variables del proyecto (Production); no se pasa ninguna con -e.
  echo "=== vercel deploy --prebuilt --prod ==="
  $VC deploy --prebuilt --prod --yes $GC 2>&1 | grep -E "Production|Ready|rror|Aliased" | head -8
else
  # Un preview no tiene las variables de Production. Se le pasan SOLO las no secretas (las de
  # docs/DEPLOY_VERCEL.md) y una clave de aplicación descartable: nada de credenciales reales.
  APPKEY="base64:$(openssl rand -base64 32)"
  ENVF=(-e APP_NAME=Preview -e APP_ENV=production -e APP_DEBUG=true -e "APP_KEY=$APPKEY"
        -e APP_URL=https://preview.invalid -e LOG_CHANNEL=stderr
        -e APP_CONFIG_CACHE=/tmp/config.php -e APP_EVENTS_CACHE=/tmp/events.php
        -e APP_PACKAGES_CACHE=/tmp/packages.php -e APP_ROUTES_CACHE=/tmp/routes.php
        -e APP_SERVICES_CACHE=/tmp/services.php -e VIEW_COMPILED_PATH=/tmp/views
        -e "SESSION_DRIVER=${SESS:-array}" -e CACHE_STORE=array -e QUEUE_CONNECTION=sync
        -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory:)
  echo "=== vercel deploy --prebuilt (preview) ==="
  $VC deploy --prebuilt --yes $GC "${ENVF[@]}" 2>&1 | grep -E "Preview|Ready|rror" | head -6
fi
