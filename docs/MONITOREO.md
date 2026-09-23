# Monitoreo de la plataforma

Pantalla: `/plataforma/monitoreo` (solo el operador de la plataforma, `platform.manage`). Este documento
explica cómo funciona, por qué está hecho así y qué falta. Cada fase del plan añade su sección; al final,
las discrepancias que se encontraron entre la documentación del proyecto y lo que de verdad hay.

> Estado: **Fase 2** (incidentes) hecha y probada en local, SIN SUBIR a GitHub ni migrada en producción
> todavía. Faltan las fases 3 a 7. El plan completo está en la conversación de diseño; aquí solo lo ya
> construido.

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

## Fase 1b — Contadores reales, búsqueda, filtros y pantalla en parciales

`monitoring.blade.php` pasó de calcular las cuatro pestañas en cada carga a un shell que solo incluye
el parcial de la pestaña abierta (`?pestana=`), con «Resumen» como una pestaña más y no un bloque fijo.

- `Monitoring/Search/{MonitoringFilters,MonitoringSearch}`: una búsqueda por texto que encuentra por
  empresa o por persona (subconsulta sobre `users`/`companies`, no `join`), no solo por las columnas
  propias de cada lista; comodines del usuario neutralizados (`BusquedaTexto`); ventana de 7 días por
  omisión al buscar, ampliable a 30/90/todo; el `context` de un suceso solo se recorre con
  `en_detalle=1`. Filtra Errores por empresa vía el desglose (`error_event_companies`), no por «la
  última empresa» del grupo, así que un error compartido entre varias no se esconde al filtrar por una.
- `Monitoring/Counters/MonitoringCounters`: los contadores del titular son totales reales (consultas
  agregadas), no `->count()` de una lista ya recortada a 15 o 25 filas para pintarse.
- `MonitoringErrorController` + `monitoring-error.blade.php`: el detalle de un grupo de errores, con
  su desglose por empresa y usuario, lo no atribuido, el aviso de «histórico v1» para los grupos de
  antes del desglose, y las acciones resolver/ignorar/reabrir.
- **Bug real encontrado y corregido**: `PlatformHealthService::pulso()` contaba `ErrorEvent` sin
  comprobar si `error_events` existía —solo comprobaba `system_events`—; con las migraciones de este
  proyecto aplicadas a mano y por partes, `system_events` puede existir sin `error_events` todavía, y
  la pantalla de monitoreo (la última que puede caerse) se caía con un 500.

**Tests (1b)**: `MonitoringCountersTest`, `MonitoringSearchTest`, `MonitoringErrorTest`. Suite completa:
1319 pasan (2 fallos preexistentes y ajenos: `HelpAssistantTest`, `SocialAutomationReportTest`).

## Fase 2 — Incident management

### El problema

Un grupo de errores dice «esto está pasando»; no dice «esto es lo bastante grave como para que
alguien lo mire ahora». Sin incidentes, una racha de 200 errores en diez minutos se ve exactamente
igual en la pantalla que un error que ocurre una vez al mes: una fila más en la lista de Errores.

### Qué se hizo

**Tablas** (`2026_09_22_1000xx`): `incidents` (código `INC-{año}-{número}`, `severity`
low|medium|high|critical, `status` open|investigating|resolved|ignored, `occurrences`,
`companies_count`, `dedupe_key` con **índice único parcial** —`CREATE UNIQUE INDEX ... WHERE status IN
('open','investigating')`, válido igual en SQLite y PostgreSQL—, `source` manual|auto);
`incident_links` (de dónde salió: grupo de error, suceso, o —Fase 3— comprobación de salud);
`incident_companies` (desglose por empresa, sobrevive a la poda del error que lo originó);
`error_events.recent_hits` (la racha de los últimos 15 minutos, no el total histórico).

**Detección** (`Monitoring/Incidents/IncidentDetector`, llamado desde `ErrorRecorder::grabar()` al
final, con el desglose ya sumado): dos disparadores, configurables en
`config('bmos.monitoreo.incidentes')` —

- **Racha**: `recent_hits ≥ 25` en 15 minutos. `ErrorRecorder` mantiene `recent_hits` al día en la
  MISMA sentencia que suma `hits` (un `CASE WHEN last_seen_at >= …` sobre la fila tal como estaba
  antes del `UPDATE`): no hace falta una consulta aparte.
- **Empresas**: el mismo grupo afecta a `≥ 3` empresas distintas en 30 minutos, contado sobre
  `error_event_companies.last_seen_at` (no el total histórico del grupo).

Los dos apuntan a la MISMA `dedupe_key` (`error:{huella}`): da igual cuál disparó, mientras el
incidente siga activo lo que llega se SUMA (ocurrencias, última detección, empresas), no abre uno
nuevo. Solo se crea uno si no hay ninguno activo con esa clave —el mismo patrón UPDATE-primero,
INSERT-si-no-había-nada que ya usa `ErrorRecorder` con los grupos de error—. **Sin autorresolución**:
un incidente resuelto que vuelve a cumplir el disparador abre uno NUEVO, con su propio código; el
índice único parcial solo protege mientras el incidente sigue `open`/`investigating`.

**`Monitoring/Incidents/IncidentService`**: abre/suma (con reintento si dos procesos chocan por el
código del mismo año), cambia el estado (investigar/resolver/ignorar/reabrir, con quién y cuándo, y su
`SystemEvent`), y lista con filtros (estado, severidad, servicio, empresa, fecha, texto por
código/título) reutilizando `MonitoringFilters` —con `severidad` añadido y `open`/`investigating`
sumados a la lista de estados compartida, que cada pantalla interpreta a su manera (ver el comentario
en `MonitoringFilters::ESTADOS`)—.

**Pantalla**: pestaña «Incidentes» (mismo patrón que Errores), `MonitoringIncidentController` +
`monitoring-incident.blade.php` para el detalle (desglose por empresa, de dónde salió, acciones), y
una tarjeta de adelanto en el Resumen. `MonitoringCounters::incidentes_activos` deja de ser `null`
—ya hay tabla— y pasa a ser el conteo real; NO se suma a `pendientes` a propósito: casi todo incidente
activo nace de un error que YA cuenta en `errores_activos`, y sumarlo doblaría la misma cosa.

**Borrado de empresa**: `CompanyEraser` limpia `incident_companies` de la empresa borrada y recalcula
`companies_count`, pero —al revés que con los grupos de error— NO borra el incidente aunque se quede
sin ninguna empresa: un incidente es un hecho que ya pasó, no un contador que se vacía.
`TenantDataPurger::KEPT` += `incident_companies` (sobrevive a una purga de prueba autoservicio, igual
que el resto del rastro). Sin poda propia todavía: el plan no la pide en esta fase.

**Familia nueva en el registro**: `'incident' => 'Incidentes'` en `MonitoringController::FAMILIAS`,
instrumentada de verdad (`incident.opened`, `incident.status_changed`), no un filtro que nadie escribe.

### Archivos

Nuevos: `database/migrations/2026_09_22_1000{00,00,00,00}_*` (incidentes, enlaces, desglose,
`recent_hits`), `app/Modules/Core/Models/{Incident,IncidentLink,IncidentCompany}.php`,
`app/Modules/Core/Monitoring/Incidents/{IncidentService,IncidentDetector}.php`,
`app/Modules/Core/Http/Controllers/MonitoringIncidentController.php`,
`resources/views/panel/admin/monitoring-incident.blade.php`,
`resources/views/panel/admin/monitoring/partials/tab-incidentes.blade.php`.
Modificados: `Monitoring/Errors/ErrorRecorder.php` (recent_hits + llamada al detector),
`Monitoring/Search/MonitoringFilters.php` (severidad, estados de incidente),
`Monitoring/Counters/MonitoringCounters.php`, `Http/Controllers/MonitoringController.php`,
`Services/{CompanyEraser,TenantDataPurger}.php`, `resources/views/panel/admin/monitoring.blade.php` +
`partials/resumen.blade.php`, `routes/web.php`, `config/bmos.php`, `.env.example`.

### Riesgos

| Riesgo | Mitigación |
|---|---|
| El código sale antes que la migración | `IncidentDetector` comprueba `DbTable::existe('incidents')`; sin ella, ningún incidente se abre y el registro de errores sigue igual. Test «sin la tabla» en `MigracionPendienteTest` e `IncidentTest` |
| Dos procesos abren el mismo incidente a la vez | Índice único parcial + patrón UPDATE-primero con reintento en el código (year+seq) |
| Umbrales mal calibrados (demasiados o muy pocos incidentes) | Configurables por variable de entorno, sin tocar código |
| Un incidente que no debía juntar dos problemas distintos | Cada disparador apunta a la huella del error (`error:{huella}`), que ya distingue clase, mensaje normalizado, origen y servicio (Fase 1a) |

### Aplicar las migraciones en producción

Mismo procedimiento que la Fase 1a (pooler de sesión, `search_path=bmos`, `--pretend` antes). Las 4
migraciones de esta fase solo AÑADEN tablas y una columna con valor por omisión (`recent_hits` en 0):
nada que migrar es destructivo, y sin migrar el código sigue funcionando exactamente como en la Fase 1b.

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
