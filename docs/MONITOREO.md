# Monitoreo de la plataforma

Pantalla: `/plataforma/monitoreo` (solo el operador de la plataforma, `platform.manage`). Este documento
explica cómo funciona, por qué está hecho así y qué falta. Cada fase del plan añade su sección; al final,
las discrepancias que se encontraron entre la documentación del proyecto y lo que de verdad hay.

> Estado: **Fase 1a** (errores multiempresa, fingerprint y sanitización). Falta la 1b (pantalla) y las
> fases 2 a 7. El plan completo está en la conversación de diseño; aquí solo lo ya construido.

## Cómo está montado el entorno (lo que condiciona todo el diseño)

| | Local (docker-compose) | Producción (Vercel, plan Hobby) |
|---|---|---|
| Cola | `redis` + `queue:work` (sin Horizon) | `sync`: el job corre dentro de la petición |
| Caché / sesión | Redis | tabla `cache` / `sessions` en la BD |
| Scheduler | `schedule:work` | NO hay: 3 crons diarios que llaman a `/tareas/*` |
| Redis | sí | no |
| Base de datos | PostgreSQL local (esquema `public`) | Supabase por pooler, esquema `bmos` |
| Migraciones | `migrate` | **a mano**: el despliegue no las corre |
| PHP | 8.4 | 8.3 |
| Tests | SQLite en memoria | — |

Consecuencia que se repite en todo el monitoreo: **el código llega antes que la migración**. Todo lo nuevo
detecta si la tabla o la columna ya existe (`DbTable`) y, si no, sigue como hasta ahora en vez de fallar.

## Fase 1a — Errores multiempresa

### El problema

`error_events` guardaba cada error UNA vez, agrupado por huella, con una sola columna `company_id`. Eso
fallaba de cuatro maneras:

1. **La empresa se pisaba en cada repetición.** Un error que afectaba a doce empresas se veía como «148
   veces» sin poder decir a quiénes; y si la última ocurrencia era de consola (sin empresa), hasta la última
   empresa conocida se perdía.
2. **La huella agrupaba de más.** Era `sha1(clase|fichero:línea)`. Toda `QueryException` nace en
   `vendor/.../Connection.php`, así que TODOS los errores de SQL eran un solo grupo, fuera la consulta que
   fuera.
3. **La huella agrupaba de menos.** El número de línea cambia con cualquier edición: el mismo fallo se partía
   en dos tras cada despliegue.
4. **Se guardaban datos que no debían.** El mensaje de una `QueryException` lleva el SQL CON los valores
   sustituidos (`Str::replaceArray`); la dirección de la petición se guardaba entera (incluido el `?secret=`
   del webhook de Evolution); y el redactor de secretos solo conocía prefijos de proveedor.

### Qué se hizo

**Estructura** (decisión D1: se conserva `error_events` como tabla de GRUPOS; renombrarla a `error_groups`
habría sido destructivo y las migraciones de producción son manuales).

| Migración | Qué añade |
|---|---|
| `2026_09_21_100000_add_grouping_columns_to_error_events_table` | `status` (active/resolved/ignored), `resolved_at/by`, `service`, `route_name`, `fingerprint_version`, `companies_count`, `users_count` + índices |
| `2026_09_21_100100_create_error_event_companies_table` | Desglose por empresa: `(error_event_id, company_id)` único, `hits`, primera y última vez |
| `2026_09_21_100200_create_error_event_users_table` | Desglose por usuario: `(error_event_id, user_id)` único, `company_id`, `hits` |
| `2026_09_21_100300_backfill_error_event_companies` | SOLO datos: lleva la empresa y usuario de los grupos antiguos al desglose (idempotente) |
| `2026_09_21_100400_add_service_and_search_indexes_to_system_events_table` | `system_events.service` + índices por servicio, usuario e IP; rellena `service` de lo existente |
| `2026_09_21_100500_add_result_index_to_polar_webhook_events_table` | Índice `(result, created_at)` |

Todas son idempotentes (`hasTable`/`hasColumn`/`hasIndex`), solo AÑADEN, y dan valor por omisión a lo nuevo:
las filas que ya había siguen siendo válidas sin tocarlas.

**Huella v2** (`Monitoring/Errors/ErrorFingerprint`): `v2_` + `sha256(clase | mensaje normalizado | origen | servicio)`
(40 caracteres, como la columna; el prefijo garantiza que no choca con ninguna antigua).

- *Mensaje normalizado* (`MessageNormalizer`): los datos de esa vez (ids, UUID, correos, IPs, fechas, números,
  lo entrecomillado con espacios) pasan a marcadores; se conserva lo que distingue un fallo: `SQLSTATE[23505]`,
  `cURL error 28`, `status code 401`, el host, un identificador entre comillas. «Timeout calling API /12345» y
  «/67890» dan la misma huella; «cURL error 28» y «cURL error 6» no.
- *Origen* (`ExceptionSnapshot`): ruta relativa + `Clase::método` del primer código propio, SIN línea. Estable
  entre despliegues; las rutas se normalizan a `/` (en Windows el mismo fallo daba otra huella) y los nombres de
  función anónima se normalizan (PHP 8.4 les mete la línea dentro, 8.3 no).
- *Errores de base de datos*: SQLSTATE + la sentencia sin valores (`?`, listas colapsadas), en vez del texto.
- *Servicio* (`ServiceResolver`): por la clase (base de datos, Redis, correo, almacenamiento), por el host al que
  se llamó (`config('bmos.monitoreo.errores.hosts')` + el de Evolution) o por el módulo donde ocurrió.
- La **ruta de la petición NO entra** en la huella (el mismo fallo de Evolution en un webhook, un job y el panel
  es UN problema); solo entra como desempate cuando no hay ni una línea de código propio.

**Escritura** (`Monitoring/Errors/ErrorRecorder`): primero SUMA al grupo y solo si no existía lo inserta (patrón
anti-carrera, igual para las filas hijas). El `company_id`/`user_id` del grupo son «el último conocido» y solo se
pisan si llegan con valor. Un grupo `resolved` que reaparece vuelve a `active`. Tope de 30 anotaciones por minuto
y por proceso: un bucle que reporta mil excepciones no se convierte en mil escrituras a la BD que ya está fallando.

**Dos modos, elige solo.** Con la migración aplicada usa la huella v2 y el desglose. Sin ella, el modo antiguo
(idéntico al de antes; lo único que mejora es que el mensaje y la dirección ya no llevan credenciales).

**Atribución de empresa** (`Support/TenantAttribution`): un usuario de empresa es de SU empresa; el operador de la
plataforma es de la que la ruta nombre (`{company}`) o de ninguna; sin sesión (jobs, webhooks), la empresa activa.
`CurrentCompany` NO sirve para esto: para el operador es «la primera empresa por id», así que sus errores, correos
de prueba y accesos quedaban como de la empresa 1. Lo usan el manejador de errores y `SystemEvent::registrar`.

**Sanitización** (`Support/SecretRedactor`): un solo redactor (antes había una copia de la expresión en cada
modelo). Tacha por APARIENCIA (`AIza…`, `sk-…`, JWT, claves de AWS, `usuario:clave@` en direcciones,
`password=…`) y por NOMBRE de clave en un detalle (`password`, `apiKey`, `client_secret`, `authorization`…).
Recorta DESPUÉS de tachar (antes partía una clave a medias). `sanitizeUrl()` quita usuario y fragmento y tacha
todos los valores de la consulta, dejando los nombres.

**Borrar una empresa** (`CompanyEraser`): el `DELETE error_events WHERE company_id = X` se habría llevado errores
de empresas que siguen vivas. Ahora resta a cada grupo lo que la empresa aportó, recalcula los contadores y borra
solo los grupos que se quedan sin ninguna ocurrencia. Sin el desglose (migración pendiente), como siempre.

**Otros cambios:** `dontReportDuplicates()` (un `report($e)` explícito seguido de un relanzamiento contaba dos
veces), retención de `error_events` en `registros:purgar` (90 días desde la ÚLTIMA vez; antes no se podaban nunca;
`BMOS_RETENCION_ERRORES`), `SystemEvent.service` deducido del tipo y el mensaje (queda vacío si no se puede saber),
`DbTable::columnas()` (una sola consulta al catálogo por tabla).

### Archivos

Nuevos: `app/Modules/Core/Monitoring/Errors/{MessageNormalizer,ExceptionSnapshot,ErrorFingerprint,ServiceResolver,ErrorRecorder,ErrorGroupBackfill}.php`,
`app/Modules/Core/Models/{ErrorEventCompany,ErrorEventUser}.php`, `app/Modules/Core/Support/{SecretRedactor,TenantAttribution}.php`,
6 migraciones (arriba), `docs/MONITOREO.md`.
Modificados: `Models/{ErrorEvent,SystemEvent}.php`, `Support/DbTable.php`, `Services/{CompanyEraser,TenantDataPurger}.php`,
`Providers/CoreServiceProvider.php` (singleton del registrador), `Console/Commands/PurgeOldRecords.php`, `bootstrap/app.php`,
`config/bmos.php`, `.env.example`.

### Riesgos

| Riesgo | Mitigación |
|---|---|
| El código sale antes que la migración | Detección de tablas/columnas; modo antiguo; pruebas «sin las tablas» |
| Grupos v1 (antiguos) con desglose aproximado | Quedan `fingerprint_version = 1`; la pantalla (1b) los marcará «histórico». No se fusionan con los nuevos |
| Coste de catálogo por petición | Solo en la ruta de error; `columnas()` memoizado |
| Tormenta de errores | Tope por proceso y por minuto |
| Tests que dependen de la huella antigua | Los 15 de `MonitoringTest` y los 15 de `SystemLogTest` pasan sin tocarlos |

### Aplicar las migraciones en producción

Las aplica el operador, un comando por fase (el código sale antes y es seguro sin migrar). Conexión por el
**pooler de sesión** `aws-1-us-east-1.pooler.supabase.com:5432` con usuario `postgres.<ref>` y `search_path=bmos`
(NO el 6543, que rompe DDL; el host directo es IPv6 y no responde desde Docker).

`migrate --pretend` NO sirve para revisar estas migraciones: al ser idempotentes, sus guardas `hasTable` dan
falso en el simulacro y salen sin enseñar el SQL. El SQL real (6 `ALTER TABLE ... ADD COLUMN`, 2 `CREATE TABLE`
con su clave foránea en cascada, 8 índices y unos pocos `UPDATE` de relleno) se comprobó ejecutándolas sobre una
base PostgreSQL desechable, con sus `down()` y con el backfill corriendo dos veces. Ninguna reescribe ni borra
filas; la única que toca datos es el backfill, que solo inserta y rellena columnas nuevas.

Verificar con `migrate:status` y con `select count(*) from error_event_companies` frente a los grupos con
`company_id`. Marcha atrás: las columnas nuevas son nulas o con valor por omisión; nunca se borra nada.

## Discrepancias entre la documentación y la implementación real

| La documentación dice | La realidad |
|---|---|
| `docker-compose.yml:2` y `MASTER_PLAN.md`: cola con Horizon | Horizon NO está instalado; el servicio corre `queue:work` |
| `MASTER_PLAN.md`, `README.md`: Redis para sesiones, caché y colas | Solo en local; en producción son `database` y `sync` |
| `MASTER_PLAN.md`, `CLAUDE.md`: n8n, DGII / e-CF | Solo variables huérfanas en `.env.example`; no hay cliente ni servicio |
| `CLAUDE.md`, `README.md`: Blade + Livewire | Livewire está instalado sin ningún componente; la UI es Blade + Alpine |
| `CLAUDE.md`: PHP 8.4 | `composer.json` pide ^8.2 y producción corre 8.3 |
| `.env.example`: `APP_TIMEZONE=America/Santo_Domingo` | `config/app.php` fija UTC (los crons corren en UTC) |
| `docs/BACKUPS.md`: respaldo diario automático | Solo lo ejecuta el scheduler local contra la BD local; Supabase no está cubierto |
| `phpunit.xml`: `PULSE/TELESCOPE/NIGHTWATCH_ENABLED` | Ninguno de esos paquetes está instalado |
| `MonitoringController::limpiar()` | Repite la poda de `registros:purgar` con un año fijo y sin usar la configuración de retención |
| `PlatformHealthService`: «todo sale de comprobaciones que ya existían» | `integraciones()` solo lee configuración, no comprueba disponibilidad (se arregla en la Fase 3) |
| `TrialMaintenanceController::ejecutar` | Ignora el código de salida de Artisan: un comando que falla se registra como «ejecutada» |
| `docker-compose.yml`: `queue:work --timeout=120` | Mayor que `retry_after=90` de la cola Redis: posible doble ejecución (no se corrige aquí) |

Hallazgos fuera del alcance del monitoreo, solo informados: `CompanyCache::flush()` incrementa a ciegas y con el
store `database` la clave no se crea (la invalidación del portal del cliente no hace nada en producción); los
avisos de Polar pueden quedar atascados en `received` si el proceso falla tras insertarlos.
