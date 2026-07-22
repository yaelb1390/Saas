# Desplegar en Vercel

> **Aviso honesto:** Vercel corre PHP como funciones **serverless**. Laravel funciona ahí, pero con
> adaptaciones. **Evolution API y n8n NO pueden correr en Vercel** — deben vivir en otro host (un
> VPS, un servicio gestionado) y Vercel los llama por HTTP. El scheduler real (cada minuto) exige
> **plan Pro** (en Hobby los cron corren 1 vez/día). Si quieres todo en un solo sitio y sin estas
> limitaciones, un VPS con `docker compose up` es más simple. Esta guía es para hacerlo en Vercel.

## Arquitectura resultante

```
Navegador ─► Vercel (Laravel serverless) ─► Supabase (PostgreSQL)
                     │                    ─► R2 / S3 (archivos)
                     │                    ─► Upstash Redis  (opcional; si no, caché en BD)
                     └─(HTTP)─► Evolution API + n8n  (en OTRO host)
```

## Servicios externos necesarios

1. **PostgreSQL gestionado** → Supabase (ya lo usas). Usa el **pooler** (puerto `6543`, modo
   transaction) para serverless, o agotarás conexiones.
2. **Almacenamiento** → Cloudflare R2 / S3 (ya tienes `AWS_*` en el `.env`). En Vercel el disco es
   efímero: `FILESYSTEM_DISK=s3`.
3. **Caché/Sesión/Colas** → dos opciones:
   - **Simple (recomendada):** driver `database` sobre Supabase (sin servicios extra).
   - **Rápida:** Upstash Redis (serverless) con `predis` (no `phpredis`).
4. **Evolution API + n8n** → en un VPS o servicio gestionado. Apunta `EVOLUTION_BASE_URL` y
   `EVOLUTION_WEBHOOK_URL` a esa URL pública.

## Archivos ya incluidos en el repo

- `api/index.php` — punto de entrada serverless (delega en `public/index.php` y crea `/tmp/views`).
- `vercel.json` — runtime `vercel-php` + enrutado (assets de `/build` estáticos, resto a Laravel).
- `.vercelignore` — excluye `vendor`, `node_modules`, `docker`, `.env`, etc.

## Variables de entorno en Vercel (Project → Settings → Environment Variables)

### App
```
APP_NAME=BM Business OS
APP_ENV=production
APP_KEY=            # genera con: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://TU-PROYECTO.vercel.app
```

### Rutas escribibles (obligatorio en serverless: solo /tmp es escribible)
```
APP_CONFIG_CACHE=/tmp/config.php
APP_EVENTS_CACHE=/tmp/events.php
APP_PACKAGES_CACHE=/tmp/packages.php
APP_ROUTES_CACHE=/tmp/routes.php
APP_SERVICES_CACHE=/tmp/services.php
VIEW_COMPILED_PATH=/tmp/views
LOG_CHANNEL=stderr
LOG_STACK=stderr
```

### Base de datos (Supabase — usa el POOLER, puerto 6543)
```
DB_CONNECTION=pgsql
DB_HOST=aws-0-xxxx.pooler.supabase.com
DB_PORT=6543
DB_DATABASE=postgres
DB_USERNAME=postgres.xxxxxxxx
DB_PASSWORD=********
```

### Caché / Sesión / Colas (opción "database", sin Redis)
```
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database
```
> Con Upstash Redis en su lugar: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
> `REDIS_CLIENT=predis`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` de Upstash, y añade
> `predis/predis` a composer (`composer require predis/predis`).

### Almacenamiento (R2 / S3)
```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=...
AWS_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com   # R2
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Integraciones (en OTRO host, Vercel solo las llama)
```
EVOLUTION_BASE_URL=https://tu-evolution.tu-dominio.com
EVOLUTION_API_KEY=...
EVOLUTION_INSTANCE=...
EVOLUTION_WEBHOOK_SECRET=...
EVOLUTION_WEBHOOK_URL=https://TU-PROYECTO.vercel.app/api/whatsapp/webhook
OPENAI_API_KEY=...
ANTHROPIC_API_KEY=...
AI_DEFAULT_PROVIDER=...
DGII_ENVIRONMENT=...
```

## Build en Vercel (Project → Settings → Build & Development)

- **Install Command:** `npm install`
- **Build Command:** `npm run build`   (genera `public/build` con Vite)
- El runtime `vercel-php` ejecuta `composer install` automáticamente.
- **NO** ejecutes `php artisan config:cache` en el build (el build no puede escribir en `/tmp`; la
  caché se genera en runtime gracias a las variables `APP_*_CACHE`).

## Migraciones (una vez, desde tu máquina contra Supabase)

Vercel no debe migrar en cada deploy. Ejecuta las migraciones apuntando a Supabase:

```bash
# .env local temporal con los DB_* de Supabase (puerto 5432 directo, no el pooler, para migrar)
php artisan migrate --force
```

Esto crea también las tablas `cache`, `sessions` y `jobs` (necesarias para los drivers `database`).

## Scheduler y colas en serverless

- **Scheduler:** añade en `vercel.json` un cron que golpee una ruta que ejecute `schedule:run`
  (requiere plan Pro para frecuencia < 1 día). Alternativa: un cron externo (cron-job.org) que
  llame a un endpoint protegido.
- **Colas:** con `QUEUE_CONNECTION=database`, un cron periódico que ejecute
  `queue:work --stop-when-empty` procesa los jobs. Alternativa serverless real: Upstash QStash
  empujando a un endpoint HTTP. Si prefieres lo más simple al inicio: `QUEUE_CONNECTION=sync`
  (los envíos de WhatsApp corren dentro de la petición; ojo con el límite de tiempo de la función).

## Pasos de despliegue

1. Commit y push de estos archivos (ver abajo).
2. En Vercel: **New Project → Import** el repo `yaelb1390/Saas`.
3. Configura Build/Install commands y **todas** las variables de entorno de arriba.
4. Deja Supabase migrado y con las tablas de cache/sessions/jobs.
5. Deploy. Prueba `https://TU-PROYECTO.vercel.app`.

## Verificación

- La home / login cargan y los assets de `/build` se sirven.
- Un login crea sesión (tabla `sessions` en Supabase se llena).
- El portal del cliente responde y la caché (tabla `cache`) se puebla; una 2ª visita no repite las
  consultas de las listas (misma garantía que en local, ahora sobre `database`).
- Si algo falla, revisa **Vercel → Deployment → Functions logs** (van a `stderr`).
