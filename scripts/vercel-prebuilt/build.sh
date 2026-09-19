#!/bin/bash
# Construye el proyecto (SOLO lo versionado en git) en Linux y deja el output en el volumen /data.
# Lo lanza run.ps1 dentro de un contenedor; ver docs/DEPLOY_VERCEL_PREBUILT.md.
#
# Montajes: /scripts (ro), /src (ro, con src.tar), /vc-src (ro, sesión del CLI), /vcproj (ro, .vercel
# del repo), /data (volumen). TARGET=production construye para --prod: un output de preview no sirve
# para producción.

mkdir -p /data/tools
if [ ! -x /data/tools/node_modules/.bin/vercel ]; then
  npm install --prefix /data/tools vercel@59.23.2 >/dev/null 2>&1
fi
VC=/data/tools/node_modules/.bin/vercel
echo "vercel $($VC --version 2>/dev/null | tail -1)"

rm -rf /data/work && mkdir -p /data/work && cd /data/work || exit 1
tar -xf /src/src.tar

# Lo que .vercelignore excluye de la función.
rm -rf vendor node_modules tests docker docker-compose.yml scripts \
       database/migrations database/seeders database/factories
find storage/logs -type f ! -name .gitignore -delete 2>/dev/null

mkdir -p .vercel
cp /vcproj/project.json .vercel/project.json
if [ "${TARGET:-preview}" = "production" ]; then
  BUILDFLAGS="--prod"; : > .vercel/.env.production.local
else
  BUILDFLAGS=""; : > .vercel/.env.preview.local
fi
echo "target del build: ${TARGET:-preview}"

# La sesión se copia a un sitio escribible: el CLI puede refrescar el token y no debe tocar el host.
rm -rf /root/vcg && cp -r /vc-src /root/vcg
echo "sesión: $($VC whoami --global-config /root/vcg 2>&1 | tail -1)"

$VC build --yes $BUILDFLAGS --global-config /root/vcg > /tmp/build.log 2>&1
echo "build exit=$?"
tail -5 /tmp/build.log

F=$(find .vercel/output/functions -name .vc-config.json 2>/dev/null | head -1)
if [ -z "$F" ]; then echo "BUILD FALLÓ: no hay .vc-config.json"; tail -30 /tmp/build.log; exit 1; fi
cp "$F" /data/vc-config.orig.json
echo "BUILD LISTO ($F)"
