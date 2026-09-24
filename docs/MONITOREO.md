# Monitoreo de la plataforma

Pantalla: `/plataforma/monitoreo` (solo el operador de la plataforma, `platform.manage`). Este documento
explica cómo funciona, por qué está hecho así y qué falta. Cada fase del plan añade su sección; al final,
las discrepancias que se encontraron entre la documentación del proyecto y lo que de verdad hay.

> Estado: **Fase 4** (colas y jobs) hecha y probada en local, SIN SUBIR a GitHub ni migrada en
> producción todavía. Faltan las fases 5 a 7. El plan completo está en la conversación de diseño;
> aquí solo lo ya construido.

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

## Fase 3 — Health checks reales (configuración ≠ disponibilidad)

### El problema

`PlatformHealthService::integraciones()` decía «bien» con solo mirar si había una clave de API
puesta. Una clave de Evolution caducada, un dominio de Polar caído o una cuenta de OpenAI sin saldo se
veían tan «bien» como uno que de verdad funcionaba, hasta que un cliente delante del mostrador decía
que el bot no contestaba. Esta fase separa las dos preguntas: `configured` (¿hay credencial?) y
`available` (¿respondió la ÚLTIMA VEZ que se le preguntó de verdad?).

### Qué se hizo

**Tablas** (`2026_09_23_1000xx`): `health_checks` (una fila por servicio, lo que se sabe AHORA:
`status` healthy|degraded|unhealthy|unknown, `configured`, `available`, `latency_ms`, `message`,
`last_error` saneado, `last_checked/success/failure_at`, rachas de `consecutive_failures/successes`,
`details` json); `health_check_results` (histórico compacto, se poda a los 14 días —`health_checks` NO
se poda: sería borrar el único dato que la pantalla enseña—); índices `wa_messages(status,created_at)`
y `(company_id,status,created_at)`.

**Contrato** (`Monitoring/Health/`): `HealthStatus` (cuatro constantes, mismo estilo que el resto del
módulo), `HealthResult` (objeto de valor que construye cada sonda), `HealthCheck` (interfaz),
`HealthRegistry` (las sondas que existen; `queue` se añade en la Fase 4, no está aquí a propósito),
`HealthStore` (guarda en las dos tablas y lleva las rachas), `HealthAggregator` (el estado GENERAL:
solo `database` es crítica para la plataforma entera —un colmado sigue cobrando aunque WhatsApp no
conteste—; un dato crítico VIEJO nunca marca DOWN por sí solo, degrada), `HealthCheckRunner`
(candado `Cache::lock` por servicio + intervalo mínimo de 30 s salvo `--forzar`; una sonda que LANZA
cuenta como caída, nunca tumba la comprobación).

**Sondas** (`Monitoring/Health/Checks/`), las seis de esta fase (no siete: `queue` es de la Fase 4):

| Sonda | Qué comprueba | Notas |
|---|---|---|
| `database` | `select 1` + latencia | Siempre «configurada»: sin ella la app no arranca |
| `redis` | `ping` | Solo si caché, cola o sesión son Redis; hoy en producción ninguna lo es → «no aplica» |
| `evolution` | `GET /instance/connectionState/{slug}` | La MISMA ruta que ya usa `EvolutionGateway::status()` en producción, contra una empresa real con la línea por QR activa. **Sin ninguna empresa así, queda «no aplica»**: no se inventó una comprobación contra la raíz del servidor (`GET {base}/`) porque no se pudo verificar contra una instancia real en esta fase —`bmos_evolution` no estaba levantado al escribir esto— y una sonda que comprueba algo distinto de lo que dice contradice el motivo de la fase |
| `ai` | Lista modelos del proveedor CONFIGURADO en el panel (OpenAI `GET /models`, Gemini `GET /models`, Anthropic `GET /models`) | NUNCA genera nada (a diferencia de `AiSettingsController::probar()`, que sí gasta un token a propósito cuando alguien lo pide); `local` → «no aplica» |
| `polar` | `GET /v1/products/?limit=1` con `PolarClient` (el mismo cliente que los cobros reales) | 403 (token sin ámbito de lectura) → `degraded`, no caído |
| `mail` | `getSymfonyTransport()->start()/stop()`, solo si `MAIL_MAILER=smtp` | Nunca manda un correo de prueba (para eso está la herramienta de «Correos de prueba») |

**Tercer disparador de incidentes** (cierra lo que la Fase 2 dejó pendiente: «servicio unhealthy con
≥2 fallos, desde F3»): `IncidentDetector::evaluarSalud()`, llamado por `HealthCheckRunner` después de
guardar cada resultado. `salud_fallos_umbral` (2, configurable) comprobaciones UNHEALTHY SEGUIDAS abren
o suman sobre `health:{servicio}`; una sana entre medias reinicia la racha. Severidad `critical` solo
para `database`, `high` para el resto.

**Ejecución**: comando `salud:comprobar {--servicio=} {--forzar} {--presupuesto=}` (sin presupuesto,
todas; con él, ordena por la que lleva más tiempo sin comprobarse primero y para al agotarse —pensado
para el tope de ~10 s de una función de Vercel—); `GET /tareas/comprobar-salud` (`tasks.check-health`,
mismo patrn `assertCron` que las demás tareas, cron diario en `vercel.json`); `POST
/plataforma/monitoreo/salud/{servicio}` (`throttle:30,1`, JSON) que el panel llama con `fetch` SOLO
para las sondas vencidas (más de 5 minutos sin comprobarse, calculado en el servidor) al abrir la
pestaña «Servicios»; el render de la pantalla en sí NUNCA hace peticiones remotas.

**`PlatformHealthService::integraciones()`**: las mismas cuatro tarjetas de siempre (polar, ia,
whatsapp, redes), pero polar/ia/whatsapp ahora leen `health_checks` cuando hay algo que leer y caen al
criterio de solo-configuración cuando no (tabla ausente, o esa sonda todavía sin su primera pasada). La
tarjeta de WhatsApp solo mira la conectividad de Evolution cuando de verdad hay una empresa que
dependa de ella —una instalación 100% Zernio no se ve «apagada» solo porque nadie usa Evolution— y
suma los mensajes fallidos de las últimas 24 h (no de toda la historia, que era el bug) más los
`pending` atascados más de 15 minutos. Nuevo tono `grave` (rojo) además de `bien`/`aviso`/`apagado`.

**Pantalla**: pestaña «Servicios» con las seis sondas, su «Comprobar ahora», y el aviso de vencidas que
se piden solas al abrir la pestaña. El titular de TODA la pantalla (la tira `<x-panel.estado>` de
arriba, siempre visible, no solo en Resumen) ahora manda por `estado_general`
(`PlatformHealthService::calcular()`, vía `HealthAggregator`) antes que por los contadores de
«pendientes»: con la plataforma DOWN, eso es lo primero que se lee.

**No verificado contra servicios reales en esta fase** (para que quien despliegue lo sepa antes de
activar el cron): la ruta raíz de Evolution como respaldo (por eso no se implementó, ver arriba); los
endpoints exactos de IA y Polar salen de la documentación de cada proveedor y de `PolarClient`, no de
una llamada real hecha desde aquí —`bmos_evolution` no estaba arriba y no se gastaron tokens ni se
llamó a la API de cobros real sin permiso—. Recomendado: `php artisan salud:comprobar --forzar` a mano
contra cada servicio configurado antes de fiarse del cron.

### Archivos

Nuevos: `database/migrations/2026_09_23_1000{00,00,00,00}_*`, `app/Modules/Core/Monitoring/Health/`
(contrato + `Checks/{Database,Redis,Evolution,Ai,Polar,Mail}Check.php`),
`app/Console/Commands/CheckServiceHealth.php`,
`app/Modules/Core/Http/Controllers/MonitoringHealthController.php`,
`resources/views/panel/admin/monitoring/partials/tab-servicios.blade.php`.
Modificados: `Services/PlatformHealthService.php` (reescrito `integraciones()`, `estado_general`
nuevo), `Http/Controllers/{MonitoringController,TrialMaintenanceController}.php`,
`Monitoring/Incidents/IncidentDetector.php` (`evaluarSalud()`), `Providers/CoreServiceProvider.php`
(`HealthRegistry` singleton), `Console/Commands/PurgeOldRecords.php`, `config/bmos.php`, `.env.example`,
`routes/web.php`, `vercel.json`, `resources/views/panel/admin/monitoring.blade.php` + `partials/resumen.blade.php`.

### Riesgos

| Riesgo | Mitigación |
|---|---|
| El código sale antes que la migración | Todo gated tras `DbTable::existe('health_checks')`; el registro de errores y la pantalla siguen funcionando sin ella |
| Una sonda mal escrita gasta cuota de una cuenta de pago en cada pasada | Nunca genera contenido (solo lista modelos/catálogo); intervalo mínimo de 30 s; `throttle:30,1` en el AJAX |
| Falso DOWN por un cron que dejó de correr | Dato crítico viejo degrada, nunca apaga |
| Endpoints de IA/Polar/Evolution no verificados contra el servicio real en esta fase | Ver «No verificado» arriba; probar a mano con `--forzar` antes de activar el cron en producción |
| Pinger externo (GitHub Actions cada 5 min) | NO añadido: el plan pide OK explícito antes de meter ese workflow en el repo público |

### Aplicar las migraciones en producción

Mismo procedimiento que las fases anteriores. Las 3 migraciones solo AÑADEN (dos tablas nuevas, dos
índices en `wa_messages`); nada destructivo, nada que migrar es obligatorio para que el código siga
funcionando exactamente como en la Fase 2.

## Fase 4 — Colas y jobs (arquitectura real, sin sistema paralelo)

### El problema

No había forma de saber si la cola se estaba vaciando o acumulando, ni si un trabajo concreto había
fallado, salvo mirando `failed_jobs` a mano. Y no había ningún agregador de «cuánto tarda esto» que
las fases futuras (HTTP, consultas de PostgreSQL) pudieran reutilizar sin reinventar un histograma
cada una.

### Qué se hizo

**`metric_buckets`** (`2026_09_24_100000`): el agregador ÚNICO que HTTP (Fase 5) y consultas lentas
(Fase 6) también usarán, distinguidos por `kind` (`http`|`job`|`db`). NO es una fila por observación,
es una fila por (kind, hora, nombre, método, empresa) que SUMA cada observación que le llega —agregada
por hora, un año de datos son unas pocas decenas de miles de filas, no millones—. `company_id` es `0`
y no `NULL` para «sin empresa»: en un índice único, PostgreSQL y SQLite tratan cada `NULL` como
distinto de cualquier otro, así que dos filas «sin empresa» del mismo minuto se habrían duplicado en
vez de sumarse.

**El histograma** (`Monitoring/Metrics/Histogram`): nueve tramos fijos (`h0`..`h8`), en milisegundos,
hasta 100/300/1000/3000/10000/30000/60000/180000 y el resto en `h8`. Sirven igual de mal —a
propósito— para una consulta de 5 ms que para un trabajo de WhatsApp de dos minutos: un histograma por
tipo daría tramos más finos, pero son tres tablas y tres cálculos que mantener en vez de uno.
`Percentiles::estimar()` calcula P50/P95/P99 interpolando en línea recta DENTRO del tramo que contiene
el percentil —es una estimación, no el valor exacto; el último tramo (sin techo) devuelve su suelo,
nunca un número inventado por encima de lo que se sabe—.

**Escritura** (`DatabaseSink`, detrás de la interfaz `MetricsSink` para el día que haya Redis en
producción): UN solo `upsert` por observación, mismo patrón UPDATE-primero-INSERT-si-no-existía que
`ErrorRecorder` e `IncidentService`. `MetricsRecorder` es la única puerta que el resto del código
conoce.

**`QueueMonitor`**: pendientes/reservados/retrasados y antigüedad del más viejo, según el driver:
`database` (una consulta agrupada sobre `jobs`), `redis` (`pendingSize`/`delayedSize`/`reservedSize`
de `RedisQueue`, con `method_exists` y no un `instanceof` contra una clase concreta), o `sync` —que no
tiene cola propia que preguntar: cada trabajo corre dentro de su petición y ya terminó— usando como
proxy los mensajes de WhatsApp `pending` desde hace más de 15 minutos, la misma señal que ya usa la
tarjeta de WhatsApp desde la Fase 3.

**`QueueEventSubscriber`**: anota cada trabajo (`JobProcessing` marca el inicio, `JobProcessed`/
`JobFailed` cierran y escriben `metric_buckets` + `SystemEvent` si falló —`queue.failed`— o tardó de
más —`queue.slow`, umbral configurable—). **NUNCA toca `CurrentCompany`**: la empresa sale del PROPIO
payload del trabajo (`bmos_company_id`, inyectado por `Queue::createPayloadUsing` al despachar, dentro
de la petición original con el tenant todavía en su sitio), nunca de `CurrentCompany` en el momento de
procesar —que puede ser otro proceso, u otro tenant si la cola es `sync`—.

**Bug real encontrado por los tests**: el cierre de `Queue::createPayloadUsing` tipaba `$queue` como
`string`, pero Laravel lo llama con `null` cuando se despacha sin nombrar una cola explícita —el caso
normal aquí, que no usa colas nombradas—. `TypeError` en cuanto se despachaba el primer trabajo.
Corregido a `?string`.

**Sonda de salud** (`QueueCheck`, ahora sí en el registro): grave si hay `pendientes_grave` trabajos o
más, o el más viejo espera `antiguedad_grave_minutos` o más; a medias con umbrales menores; «no
aplica» con `sync`. Alimenta el tercer disparador de incidentes que la Fase 2 dejó pendiente
(`salud_fallos_umbral` fallos seguidos → `health:queue`).

**Poda**: `failed_jobs` (por `failed_at`) y `metric_buckets` (por `bucket_start`), 30 días los dos.
`metric_buckets` entra en `TenantDataPurger::KEPT` (es observabilidad nuestra, no datos que el cliente
escribió) y `CompanyEraser` la borra al borrar la empresa entera —con un `DELETE` liso: al revés que
los errores y los incidentes, cada fila ya es de una sola empresa, no hay desglose que recalcular—.

**Pantalla**: tarjeta «Colas y trabajos» en la pestaña Servicios (pendientes/procesando/retrasados/
antigüedad, P95 por cola de las últimas 24 h, últimos fallos); «N trabajos pendientes» informativo en
el Resumen —no se suma al titular de «cosas piden atención»: un puñado de pendientes es lo NORMAL en
una cola que funciona, y sumarlo habría convertido cada carga en ruido—. Familia `queue` en el filtro
del Registro, instrumentada de verdad (`queue.failed`, `queue.slow`).

### Archivos

Nuevos: `database/migrations/2026_09_24_100000_create_metric_buckets_table.php`,
`app/Modules/Core/Monitoring/Metrics/{Histogram,Percentiles,Observation,MetricsSink,DatabaseSink,
MetricsRecorder}.php`, `app/Modules/Core/Monitoring/Queues/{QueueMonitor,QueueEventSubscriber}.php`,
`app/Modules/Core/Monitoring/Health/Checks/QueueCheck.php`.
Modificados: `Providers/CoreServiceProvider.php` (`MetricsSink` binding, `createPayloadUsing`,
`Event::subscribe(QueueEventSubscriber::class)`), `Http/Controllers/MonitoringController.php`
(detalle de colas para la pestaña Servicios), `Monitoring/Counters/MonitoringCounters.php`
(`jobs_pendientes`), `Services/{TenantDataPurger,CompanyEraser}.php`, `Console/Commands/
PurgeOldRecords.php`, `config/bmos.php`, `.env.example`, `resources/views/panel/admin/monitoring/
partials/{tab-servicios,resumen}.blade.php`.

### Riesgos

| Riesgo | Mitigación |
|---|---|
| El código sale antes que la migración | `DatabaseSink`/`QueueCheck` comprueban `DbTable::existe('metric_buckets')`; sin ella, cero filas y ningún 500 |
| Un histograma compartido por tres tipos muy distintos en escala | Decisión ya tomada en el plan (D2); nueve tramos hasta tres minutos cubren de una consulta de PostgreSQL a un trabajo de IA |
| `CurrentCompany` tocado desde un listener de cola | El subscriber NUNCA la toca; la empresa viaja en el payload del propio trabajo (test dedicado) |
| Cola larga que no se nota | `QueueCheck` la refleja como servicio degradado/caído, y eso SÍ suma a «servicios con aviso» |

### Aplicar las migraciones en producción

Mismo procedimiento que las fases anteriores. La única migración de esta fase solo AÑADE una tabla;
nada que migrar es obligatorio para que el código siga funcionando exactamente como en la Fase 3.

## Fase 5 — Rendimiento HTTP

### El problema

No había forma de saber si el panel o la API iban lentos salvo notarlo a ojo. Ni P50/P95/P99, ni
qué endpoint concreto se está poniendo lento, ni si es un módulo entero (POS, Inventario) o uno
suelto.

### Qué se hizo

**Sin migración**: reutiliza `metric_buckets` (Fase 4) con `kind='http'`. El histograma, los
percentiles y el patrón UPDATE-primero-INSERT-si-no-existía ya estaban.

**`RecordRequestMetrics`** (middleware global, `$middleware->append` en `bootstrap/app.php`):
TERMINABLE a propósito —`handle()` no hace nada; todo el trabajo va en `terminate()`, que Laravel
llama DESPUÉS de que la respuesta ya salió, así que medir no le añade latencia a nadie—. Excluye
`/up` (comparado por `uri()`, no por `getName()`: la ruta de salud de Laravel se registra con un
closure sin nombre) y los archivos estáticos por extensión.

**Muestreo por estratos con peso entero** (`Observation::$weight`, nuevo): un 5xx o una petición
lenta (`lento_ms`) se guarda SIEMPRE, con peso 1; el resto, 1 de cada N (`uno_de_cada`), con
peso N —la petición que «gana» el sorteo representa a las N que no se guardaron—. `DatabaseSink`
multiplica por el peso `total`, `warnings`, `errors`, `sum_ms` y el tramo del histograma que le
toque; **`max_ms` nunca se multiplica** (es un máximo, no una suma). Con esto el total sigue siendo
el total real sin escribir una fila por cada petición normal. Interruptor general `BMOS_METRICAS`
(apagado en `phpunit.xml`; los tests que lo necesitan lo encienden con `config()`).

**`ModuleResolver`** (memo por ruta): a qué módulo pertenece una petición, en tres pasos —
middleware `module:X` de la ruta, namespace del controlador (`App\Modules\{X}\...`), primer
segmento de la URI—, cada uno cruzado contra `ModuleRegistry::exists()` para que una corazonada que
no es un módulo de verdad no cuente. Sin ninguna de las tres, `'app'` (núcleo compartido).

**`MetricsQuery`**: lee lo que `RecordRequestMetrics` escribe —resumen de la aplicación (requests,
tasa de error, P50/P95/P99), desglose por módulo, y los endpoints más lentos con un mínimo de
muestras (`muestras_minimas`) para que un endpoint casi sin tráfico no encabece el ranking por una
sola petición de casualidad—.

**Empresa**: `TenantAttribution::companyId()`, la misma regla que ya usan `ErrorRecorder` y
`SystemEvent` —nunca la «primera empresa» del operador de la plataforma—.

**Tres fallos que el propio desarrollo encontró y quedaron corregidos desde el primer commit**:
`LARAVEL_START` no está definida cuando la app arranca fuera de `public/index.php` (los tests, por
ejemplo) → `terminate()` cae a `REQUEST_TIME_FLOAT`; la ruta `/up` no tiene nombre → exclusión por
`uri()`; y un endpoint muestreado 1 de cada N debía sumar exactamente N al total, no 1 (test
dedicado, forzando el sorteo con reintentos hasta que la petición «gana»).

**Pantalla**: pestaña «Rendimiento» (filtro por empresa, banner si el interruptor está apagado,
requests/tasa de error/P50/P95/P99, por módulo, endpoints más lentos) y una quinta tarjeta «P95 HTTP
24 h» en el Resumen, sin tendencia frente a ayer —ese histórico no se guarda—.

### Archivos

Nuevos: `app/Modules/Core/Http/Middleware/RecordRequestMetrics.php`,
`app/Modules/Core/Monitoring/Metrics/{ModuleResolver,MetricsQuery}.php`,
`resources/views/panel/admin/monitoring/partials/tab-rendimiento.blade.php`,
`tests/Feature/Core/Monitoring/HttpMetricsTest.php`.
Modificados: `Monitoring/Metrics/{Observation,MetricsRecorder,DatabaseSink}.php` (peso), `bootstrap/
app.php` (middleware global), `Http/Controllers/MonitoringController.php` (pestaña y P95 del
Resumen), `resources/views/panel/admin/monitoring.blade.php` (pestaña nueva), `resources/views/
panel/admin/monitoring/partials/resumen.blade.php` (quinta tarjeta), `config/bmos.php`,
`.env.example`, `phpunit.xml`.

### Riesgos

| Riesgo | Mitigación |
|---|---|
| El código sale antes que la migración | No aplica: reutiliza `metric_buckets`, ya migrada en la Fase 4 |
| Coste por petición del middleware | Terminable: corre después de responder; sin sink (tabla ausente) es un `DbTable::existe()` memoizado y nada más |
| Muestreo sesgado si `uno_de_cada` es alto y hay poco tráfico | Documentado; el operador ajusta `BMOS_METRICAS_UNO_DE_CADA` por variable de entorno sin desplegar |
| Un `ModuleResolver` que adivina mal | Cruzado contra `ModuleRegistry::exists()` en los tres pasos; sin acierto, cae a `'app'`, nunca un módulo inventado |

### Aplicar las migraciones en producción

Ninguna. Esta fase no añade tabla ni columna: solo lee y escribe en `metric_buckets` (Fase 4). Basta
con desplegar el código y, si se quiere, fijar `BMOS_METRICAS_*` en las variables de Vercel (los
valores por defecto ya son razonables sin tocar nada).

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
