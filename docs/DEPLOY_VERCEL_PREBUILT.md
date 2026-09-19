# Desplegar en Vercel con un output prebuilt

> **Desde el 2026-09-19 los deploys que Vercel construye en la nube (`git push`, `vercel --prod`) salen
> "Ready" pero rotos.** Hasta que se arregle aguas arriba, se despliega con el script de esta página.
> No hagas `git push` esperando que despliegue: no llega a producción (ver "Reglas").

## Qué pasa

- **Síntoma:** el deploy termina "Ready", pero todas las rutas dan `500 FUNCTION_INVOCATION_FAILED`. En
  `vercel logs`: `Cannot find module '/var/task/launcher.launcher' imported from /opt/rust/nodejs.js`.
- **Causa:** el bootstrap nuevo de Vercel (Managed Images, 2026-08-10) ya no admite la forma en que el
  runtime `vercel-php` declara su handler. Es un bug conocido y abierto:
  [vercel-community/php#650](https://github.com/vercel-community/php/issues/650). No lo arregla reintentar,
  vaciar la caché ni cambiar de versión de `vercel-php`.
- **Por qué no basta parchear el runtime desde `buildCommand`:** el CLI de Vercel carga el runtime en
  memoria antes de ejecutar el `buildCommand`, así que el parche en disco llega tarde.

## Qué corrige este camino

El proyecto se construye en un contenedor Linux y se corrige la config de la función
(`.vercel/output/functions/api/index.func/.vc-config.json`) antes de subirla con `--prebuilt`:

1. **Modo Lambda:** `handler: "launcher.js"`, `launcherType: "Nodejs"` y
   `awsLambdaHandler: "launcher.launcher"`.
2. **`LAMBDA_TASK_ROOT`:** el launcher lo usa para localizar `/user` y `/php` y el bootstrap nuevo no lo
   define (`spawn php ENOENT`). Es una variable reservada: declararla en la config hace fallar el deploy, por
   eso se define en código, en un launcher "puente".
3. **Cuerpo de las peticiones:** llega como array de bytes y el launcher exige un `Buffer` (sin esto, los
   `POST` dan 502). El puente lo convierte.

El detalle está en `scripts/vercel-prebuilt/patch-config.js`.

## Uso

Requisitos: Docker Desktop, Vercel CLI con sesión iniciada, el proyecto vinculado (`vercel link`, que crea
`.vercel/project.json`) y PowerShell.

```powershell
.\scripts\vercel-prebuilt\run.ps1 todo            # preview
.\scripts\vercel-prebuilt\run.ps1 todo -Prod      # producción
```

Se despliega **HEAD** (lo versionado), no el árbol de trabajo. `build` y `deploy` también se pueden lanzar por
separado. Un output construido para preview no sirve para producción: `-Prod` reconstruye.

**Flujo seguro para producción** (la auto-asignación de dominios está apagada, así que el deploy nuevo NO
queda en vivo hasta que lo promuevas):

```powershell
.\scripts\vercel-prebuilt\run.ps1 todo -Prod
vercel curl /login --deployment <url> --yes -- -sS -o NUL -w "HTTP %{http_code}"   # debe dar 200
vercel promote <url> --yes
```

Tras promover, comprueba `https://bmos.bm1390.cloud/login` y `/planes` (esta lee la base de datos). Si algo
falla: `vercel promote <url del deploy anterior> --yes`. En Git Bash, prefija `MSYS_NO_PATHCONV=1` a
`vercel curl`, o la ruta `/login` se convierte en una ruta de Windows.

Un **preview** no tiene las variables de Production: el script le pasa solo las no secretas y una `APP_KEY`
descartable. Sirve para comprobar que la función arranca (`/up` → 200, `/login` → 200), no para probar
funciones que necesitan la base de datos.

## Reglas

- **`autoAssignCustomDomains` debe seguir en `false`.** Con `true`, un `git push` a `master` construye en la
  nube un deploy roto y lo publica solo en el dominio de producción. `vercel promote` lo apaga y un deploy
  `--prod` nuevo lo reactiva; compruébalo con `MSYS_NO_PATHCONV=1 vercel api /v9/projects/<id>` y apágalo con
  `-X PATCH -F autoAssignCustomDomains=false`.
- No uses `vercel --prod` a secas: sube el árbol de trabajo y lo construye la nube (roto).
- El script monta la sesión del CLI (`%APPDATA%\xdg.data\com.vercel.cli`) **solo lectura** en un contenedor
  desechable con el CLI oficial; la copia a un sitio escribible dentro del contenedor y nunca modifica la del host.

## Cuándo volver al flujo normal

Cuando el issue #650 tenga arreglo: haz un `vercel deploy` normal (preview) y pruébalo con
`vercel curl /up --deployment <url>`. Si da 200, vuelve a `git push`, reactiva la auto-asignación y borra
`scripts/vercel-prebuilt/`.

## Detalles que muerden

- `deploy.sh` borra `.vercelignore` antes de subir: en un deploy prebuilt sus reglas chocan con ficheros que la
  función sí referencia y la nube falla con `ENOENT readlink storage/framework/cache/.gitignore`.
- El deploy corre **dentro** del contenedor: desde Windows se pierde el bit ejecutable del binario de PHP.
- El volumen `bmos-vercel-prebuilt` guarda la herramienta y el último build (~1 GB). Bórralo con
  `docker volume rm bmos-vercel-prebuilt` cuando no lo necesites.
