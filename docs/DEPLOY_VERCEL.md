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
QUEUE_CONNECTION=sync
```
> `QUEUE_CONNECTION=sync` a propósito: en Vercel **no hay ningún proceso en segundo plano** que
> vacíe la cola. Con `database`, los correos encolados (bienvenida, confirmación de pago, aviso de
> vencimiento) se quedarían en la tabla `jobs` para siempre y nadie se enteraría. Con `sync` se
> envían durante la petición; el coste es la latencia del SMTP en tres acciones poco frecuentes.

> Con Upstash Redis en su lugar: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
> `REDIS_CLIENT=predis`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` de Upstash, y añade
> `predis/predis` a composer (`composer require predis/predis`).

### Correo — IMPRESCINDIBLE
Sin estas variables `MAIL_MAILER` cae a `log` y **ningún correo llega a nadie**: ni el enlace para
recuperar la contraseña, ni la bienvenida, ni los avisos de vencimiento. El sistema no da error, se
comporta como si hubiera enviado. Es el fallo que tuvo este proyecto durante meses.
```
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=...          # el usuario de Brevo, acaba en @smtp-brevo.com
MAIL_PASSWORD=...          # la CLAVE SMTP, no la contraseña de la cuenta
MAIL_FROM_ADDRESS=...      # remitente VERIFICADO en el proveedor
MAIL_FROM_NAME="BM Business OS"
```
> El remitente decide si el correo llega. Un dominio sin verificar hace que los mensajes se rechacen
> o acaben en no deseados, y eso **no lo detecta ningún test**: hay que enviarse uno de verdad.

> Si el proveedor restringe el envío por IP (Brevo lo trae activado), **hay que desactivarlo**: las
> funciones de Vercel salen por direcciones que cambian y no se pueden listar. El síntoma es un
> `525 Unauthorized IP address` en los registros.

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

En serverless no hay ningún proceso que viva entre peticiones, así que lo que en un servidor normal
hace `schedule:work` aquí lo tiene que provocar una llamada HTTP. Ya existen cuatro direcciones de
mantenimiento, todas protegidas con el mismo secreto compartido (`CRON_SECRET`), que Vercel Cron
manda solo en la cabecera `Authorization: Bearer …`:

| Dirección | Qué hace | Cada cuánto |
|---|---|---|
| `/tareas/purgar-pruebas` | Borra los datos de las pruebas caducadas hace >24 h | diario |
| `/tareas/avisar-vencimientos` | Avisa por correo de las suscripciones por vencer | diario |
| `/tareas/purgar-registros` | Poda la auditoría y los sucesos del sistema | diario |
| `/tareas/drenar-cola` | Ejecuta los trabajos en cola y vuelve | cada minuto |

### Solo una de las cuatro está activada

`vercel.json` no tenía bloque `crons`, así que hasta ahora **no se ejecutaba ninguna**. Está puesta
la poda de registros, que es la que evita que la base se llene sola. Las otras dos diarias están
escritas y probadas desde hace tiempo pero siguen **sin activar**, a propósito, porque encenderlas
tiene efectos hacia fuera y esa decisión es tuya:

```json
{ "path": "/tareas/purgar-pruebas", "schedule": "0 6 * * *" },
{ "path": "/tareas/avisar-vencimientos", "schedule": "0 7 * * *" }
```

- **`purgar-pruebas` BORRA datos de verdad**: los de las cuentas de prueba caducadas hace más de 24 h.
  Es justo lo que se diseñó que hiciera, pero desde el día que se active deja de haber vuelta atrás.
- **`avisar-vencimientos` MANDA CORREOS a clientes reales.** Mientras siga apagada no sale ni uno, y
  eso probablemente te esté costando renovaciones; pero encender el envío a tu lista de clientes no
  es algo que deba hacer yo sin que lo sepas.

Sobre el plan: en Hobby caben **100** tareas por proyecto, así que el número no es problema. Lo que
Hobby limita es la **frecuencia** —una vez al día como mucho, y con ±59 min de imprecisión—; una
expresión más frecuente **falla en el despliegue**. Por eso la de la cola, que es por minuto, no está
puesta: en Hobby no llegaría a desplegar.

### La cola

Hoy va en `QUEUE_CONNECTION=sync`, que quiere decir que **no hay cola**: mandar el WhatsApp,
contestar con IA y transcribir un audio ocurren DENTRO de la petición, con el cliente esperando a que
respondan OpenAI y Evolution API, una detrás de otra.

Para sacarlos de ahí hacen falta las dos cosas **a la vez**:

1. `QUEUE_CONNECTION=database` en las variables de Vercel.
2. Un cron por minuto a `/tareas/drenar-cola` (solo Pro), o un servicio externo que golpee esa
   dirección —QStash, cron-job.org— con la cabecera del secreto.

**Cambiar solo la primera es peor que no cambiar nada**: los trabajos se encolan y no los ejecuta
nadie, así que los mensajes dejan de enviarse sin dar ningún error. Van juntas o no va ninguna.

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
