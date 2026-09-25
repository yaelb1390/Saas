# Auditoría — Módulo Social Commerce (FASE 0)

Fecha: 2026-09-24
Alcance: solo lectura. No se modificó código funcional durante esta auditoría.
Objetivo: entender qué existe hoy antes de diseñar la automatización de comentarios de Instagram (palabra clave → producto → precio → plantilla → respuesta, sin IA) pedida en el prompt maestro.

---

## 0. Hallazgo crítico: gran parte de lo pedido YA EXISTE, pero detrás de un proveedor externo

Antes de leer el resto del documento: **`app/Modules/Social` ya implementa automatizaciones de comentarios de Instagram por palabra clave**, y **`app/Modules/WhatsApp` ya conecta esas conversaciones al CRM**. No se parte de cero. Esto cambia el plan de implementación del prompt maestro (fases 4-10 están mayormente hechas) y obliga a una decisión de producto antes de diseñar nada (ver §15 y §16).

**Zernio** (`https://api.zernio.com`) es un proveedor SaaS externo tipo "bandeja social unificada" (similar a Ayrshare/Metricool) que este proyecto usa como intermediario hacia las APIs oficiales de Meta. No es una API interna ni algo construido por este equipo. A través de `ZernioClient` (`app/Modules/Social/Services/ZernioClient.php`) el sistema ya:

- Conecta cuentas de Instagram/Facebook/TikTok/etc. por empresa (OAuth gestionado por Zernio).
- Lista publicaciones (`publishedPosts()`), programa/publica contenido.
- Crea y administra **automatizaciones de comentarios por palabra clave** (`/v1/comment-automations`): coincidencia exacta/por palabra con tolerancia a errores tipográficos, gatillo por comentario o respuesta a historia, respuesta pública + DM privado, **hasta 5 variantes rotativas** de cada texto, botón con URL, retraso configurable antes del DM (antispam), filtro "solo seguidores", detección de reglas solapadas, reportes con métricas y "comentarios sin coincidencia".
- Recibe webhooks de Zernio (`ZernioWebhookController`) con verificación HMAC-SHA256 por empresa.

Lo que **no existe** en Zernio ni en el módulo Social es el concepto de "producto" ni "precio": las respuestas son texto libre escrito a mano por el comerciante. Tampoco crea contactos/oportunidades en el CRM, ni construye enlaces `wa.me`, ni registra de qué automatización vino un cliente. Ese es el trabajo real y acotado de "Social Commerce": **una capa de reglas producto↔precio↔plantilla que genera el texto que se envía a Zernio**, no un sustituto de Zernio ni una integración directa nueva con la API de Meta.

Detalle completo de capacidades existentes vs. pedidas: ver §7 (Social) y §8 (WhatsApp) más abajo, y la tabla de §15.

---

## 1. Stack actual

- **Backend**: Laravel 12, PHP `^8.2` (composer.json exige 8.2, CLAUDE.md pide 8.4 — el proyecto real corre en 8.2+; no se ha migrado a 8.4).
- **Base de datos**: PostgreSQL. En producción, Supabase con el **schema `bmos`** (no `public`), seleccionado por `DB_SEARCH_PATH` a nivel de conexión (`config/database.php`), vía pooler de sesión `aws-1-us-east-1.pooler.supabase.com`. Localmente corre en Docker con `search_path=public`. Las migraciones NO las corre Vercel; se aplican a mano contra Supabase.
- **Cola/caché**: Redis 7 (`docker-compose.yml`) disponible, pero el **driver de cola en producción es `database`**, no `redis` — Vercel no tiene un worker persistente, así que la cola se drena por una ruta HTTP (`/tareas/drenar-cola`) golpeada por cron, con un presupuesto de ~25s por invocación (`config/queue.php:144-147`). Cualquier job nuevo debe caber en esa ventana.
- **Frontend**: Blade + Alpine.js. **Livewire está en `composer.json` (`^4.3`) pero no se usa en ningún componente del panel** — el patrón real es Controller clásico → vista Blade, con Alpine para interactividad (`x-data="..."`) y componentes Blade reutilizables bajo `resources/views/components/panel/`. Tailwind para estilos.
- **Autenticación**: Laravel Fortify (2FA por TOTP + passkeys/WebAuthn), Sanctum para tokens de API.
- **Roles/permisos**: `spatie/laravel-permission` con "teams" activado (`team_foreign_key = company_id`) — los roles están particionados por empresa.
- **Auditoría**: `owen-it/laravel-auditing`, aplicada selectivamente (no a las 70+ tablas).
- **Pagos/suscripciones**: Polar (webhooks propios, ya con manejo modelo de idempotencia y verificación de firma — ver §16).
- **WhatsApp**: dos vías intercambiables — Evolution API (self-hosted, sesión no oficial, riesgo de baneo) y Zernio (API oficial de WhatsApp Cloud, solo dentro de la ventana de 24h que abre el cliente).
- **IA**: proveedor configurable (OpenAI/Gemini/Anthropic) para el bot de WhatsApp y RAG documental — **irrelevante para este módulo**, ya que el prompt maestro prohíbe IA en Social Commerce.
- **n8n**: mencionado en CLAUDE.md como integración planeada. Hoy solo hay *scaffolding de infraestructura*: un servicio opcional en `docker-compose.yml` (perfil `n8n`, puerto 5679) y dos variables ya declaradas en `.env.example` (`N8N_BASE_URL`, `N8N_WEBHOOK_SECRET`) que **ningún archivo PHP lee todavía**. No hay cliente HTTP, config, ruta ni job que llame a n8n. Varios eventos de dominio (`WhatsAppMessageReceived`, y el comentario en `CoreServiceProvider.php:74`) están documentados explícitamente como "el punto de enganche para n8n", pero nada los consume con ese fin hoy.

---

## 2. Arquitectura

Monolito modular, no un paquete Laravel por módulo sino una convención de carpetas: `app/Modules/<Nombre>/{Models,Services,Http/Controllers,Http/Requests,Jobs,Events,Listeners,Enums,Providers,Support}`. Cada módulo tiene su propio `<Nombre>ServiceProvider`, registrado explícitamente en `bootstrap/providers.php` (no hay auto-discovery). 20 módulos activos hoy: AI, Billing, CRM, Cash, Core, Dealer, Delivery, Finance, HR, Help, Inventory, Loans, POS, Printing, Purchasing, Quotes, Rental, Reports, Sales, Social, WhatsApp.

Patrones consistentes en todos los módulos (confirmados en CRM/Sales/Inventory/WhatsApp/Social/Core):
- **Modelo delgado, lógica en Service**: `CrmService::openOpportunity()`, `SaleService::complete()`, `WhatsAppService`, etc. Ningún controlador contiene lógica de negocio de peso — coincide con la regla de CLAUDE.md.
- **DTOs** para construir entidades desde datos externos (`CreateCustomerData`, `CreateCompanyData`).
- **Eventos + Listeners registrados a mano** en `boot()` de cada `ServiceProvider` vía `Event::listen(Evento::class, Listener::class)` — no hay un array `$listen` de Laravel clásico, y los listeners no son `ShouldQueue` en sí mismos (el trabajo pesado se despacha como Job desde dentro del listener/handler).
- **Módulos vendibles como catálogo cerrado**: `App\Modules\Core\Support\ModuleRegistry::MODULES` (`app/Modules/Core/Support/ModuleRegistry.php:20-49`) enumera las 18 claves comercializables (`pos`, `crm`, `whatsapp`, `social`, …). Un `Plan` (`app/Modules/Core/Models/Plan.php`) incluye un subconjunto de esas claves; `Company::hasModule()` decide acceso cruzando el plan de la suscripción con un posible override manual. **Esto es clave para §16**: cualquier funcionalidad nueva que se quiera vender por separado necesita una clave nueva en este registro y aparecer en algún plan — no es solo "una carpeta de código nueva".
- **`social` ya es una clave de módulo existente**, descrita literalmente como "Publica en Instagram, Facebook y TikTok a la vez" y explícitamente separada de `whatsapp` porque "aquello es conversación con un cliente concreto, esto es difusión" (`ModuleRegistry.php:31-33`). La automatización de comentarios ya vive bajo esta clave.

---

## 3. Sistema de autenticación

- Guard único `web` (sesión), modelo `App\Models\User` (nota: vive en `app/Models`, no en `app/Modules/Core/Models`, inconsistencia menor con el resto del proyecto).
- `User` implementa `Auditable`, `HasApiTokens` (Sanctum), `HasRoles` (spatie), `TwoFactorAuthenticatable` (Fortify), `Notifiable`.
- **El registro propio de Fortify está desactivado a propósito** (`config/fortify.php:181`, comentado con una nota explícita: activarlo creaba usuarios huérfanos sin `company_id`, saltándose el aislamiento por tenant). El registro real es un flujo propio que crea empresa+sucursal+almacén+roles+usuario dueño de forma atómica.
- 2FA: TOTP + passkeys/WebAuthn, con `confirmPassword: true`. Login bloqueado si `is_active = false` incluso con contraseña correcta (`FortifyServiceProvider::authenticateUsing`).
- Rate limiting de auth: `login` 5/min por `email|ip`, `two-factor` 5/min, `passkeys` 10/min — únicos *named limiters* del proyecto; el resto del sistema usa `throttle:N,1` inline por ruta.

---

## 4. Multi-tenancy

Mecanismo limpio y ya maduro — **cualquier tabla/modelo nuevo debe seguirlo exactamente**, es el punto no negociable de todo este proyecto:

1. Columna `company_id` en la tabla, con `->constrained()->cascadeOnDelete()` (o sin FK, ver excepción de tablas de log en §5).
2. El modelo usa el trait `App\Modules\Core\Tenancy\BelongsToCompany` e implementa `HasCompany`.
3. `BelongsToCompany` registra un `CompanyScope` (global scope que filtra `WHERE company_id = CurrentCompany::id()` solo si hay tenant activo) y autocompleta `company_id` al crear si no se pasó explícitamente.
4. `CurrentCompany` es el único origen de verdad de "qué empresa está activa" — nunca se debe confiar en un `company_id` que llegue del cliente HTTP.
5. La resolución del tenant ocurre en middleware (`SetCurrentCompany` para web, `SetApiCompany` para API, ambos antepuestos a `SubstituteBindings` en `bootstrap/app.php`), de forma que el *route-model-binding* ya filtra por tenant — un `{customer}` de otra empresa da 404 automáticamente, no por una comprobación manual en el controlador.
6. **Los webhooks no pasan por ese middleware** (no hay sesión ni token de usuario): resuelven el tenant ellos mismos desde un secreto/token en la URL o metadata firmada, y llaman `CurrentCompany::set(...)` a mano — así lo hacen tanto `ZernioWebhookController` como `EvolutionWebhookController`.
7. **Los jobs en cola tampoco tienen sesión**: `CoreServiceProvider::boot()` usa `Queue::createPayloadUsing()` para grabar `bmos_company_id` en cada payload al despachar, y cada `handle()` de Job debe inyectar `CurrentCompany $currentCompany` y llamar `->set(...)` como primera línea (ver ejemplo en `SendWhatsAppMessage::handle()`).

No hay UUIDs en ningún lado: todo `id` es `bigint` autoincremental.

---

## 5. Base de datos

- 156 migraciones (`2026_07_10` → `2026_09_24`), nomenclatura `YYYY_MM_DD_HHMMSS_create_<tabla>_table.php` / `add_<columna>_to_<tabla>_table.php`.
- `companies` (raíz del tenant): `id, name, slug (unique), legal_name, tax_id, email, phone, address, currency (default DOP), timezone, logo_path, is_active, settings (json), modules (json, NULL=todos), social_api_key (encrypted), ai_assistant, timestamps, deleted_at`.
- **Patrón estándar para tabla de negocio nueva** (idéntico en customers/products/opportunities/wa_conversations/etc.):
  ```php
  $table->id();
  $table->foreignId('company_id')->constrained()->cascadeOnDelete();
  // ...columnas...
  $table->timestamps();
  $table->softDeletes();
  $table->index(['company_id', '<columna_de_estado>']);
  ```
  Las tablas hijas (líneas de detalle) repiten `company_id` propio en vez de depender solo del join al padre (confirmado en `sale_items`, `invoice_items`, etc.) — decisión deliberada, no descuido.
- **Excepción — tablas de log/auditoría/monitoreo** (patrón más reciente, usado por ejemplo en `metric_buckets`, `error_event_companies`, y el que debe copiar cualquier tabla de eventos de webhook nueva): `$table->unsignedBigInteger('company_id')` **sin FK** (el rastro de una empresa borrada no debe desaparecer por cascada — lo gestiona `CompanyEraser`/`TenantDataPurger` explícitamente), sin `softDeletes()`, con guarda de idempotencia `if (Schema::hasTable(...)) return;` y `declare(strict_types=1);` al inicio del archivo.
- **Mejor plantilla existente para un log de eventos de webhook**: `polar_webhook_events` (`database/migrations/2026_08_10_100000_create_polar_webhook_events_table.php`) — `event_id` **único** (clave de idempotencia), `type`, `result` (`received/applied/ignored/unresolved`), `company_id` nullable sin FK, `payload` json completo, índice `[type, created_at]`. El handler (`PolarWebhookHandler::claim()`) inserta primero y confía en la restricción única de la BD para detectar duplicados bajo concurrencia — no hace `exists()` antes (eso tiene una condición de carrera documentada en el propio código). **Esta es literalmente la tabla que hay que clonar** para el log de webhooks de Instagram/Zernio de Social Commerce.
- Sin factories dedicadas para la mayoría de modelos (solo `UserFactory` y `CRM\CustomerFactory` existen); los seeders llaman servicios directamente. Los tests fuerzan SQLite en memoria (`phpunit.xml`) y siguen el patrón `CurrentCompany::forget()` → crear empresa vía `CompanyService`/DTO → `CurrentCompany::set($company->id)` → crear filas sin pasar `company_id` (se autocompleta).

### Tablas ya existentes relevantes para Social Commerce

| Tabla | Módulo | Columnas clave |
|---|---|---|
| `companies.social_api_key` | Core | credencial Zernio cifrada por empresa |
| `social_welcome_settings` | Social | `company_id` (unique), `token` (webhook, único), `secret` (encrypted, HMAC), `zernio_webhook_id` |
| `social_welcomes` | Social | dedup de bienvenidas por conversación; **no hay `social_automations`, `social_posts` ni `social_accounts` locales — viven enteramente en Zernio** |
| `customers` | CRM | `company_id, name, email?, phone?, tax_id?, cedula?, address?, latitude?, longitude?, notes?, is_active` |
| `opportunities` | CRM | `company_id, customer_id?, pipeline_id, stage_id, title, amount, status(open/won/lost), expected_close_date?, closed_at?, user_id?` — **sin columna de origen/canal** |
| `pipelines` / `pipeline_stages` | CRM | genéricas, cualquier empresa nueva recibe un pipeline "Ventas" con 5 etapas por defecto |
| `products` | Inventory | `company_id, category_id?, sku, name, description?, barcode?, unit, cost, price, track_stock, is_active, is_available, image_path?, tracks_serials` — **sin `currency` ni `discount_price`** |
| `wa_conversations` / `wa_messages` | WhatsApp | patrón directo a clonar para conversaciones/mensajes de Instagram |
| `wa_templates` | WhatsApp | plantillas `{{var}}`, sin tabla de seguimiento de uso |
| `polar_webhook_events` | Core | plantilla a clonar para el log de eventos de webhook |

---

## 6. Customers

`App\Modules\CRM\Models\Customer` (`app/Modules/CRM/Models/Customer.php`). Usa `BelongsToCompany`, `SoftDeletes`, `AuditableTrait`. Relaciones: `opportunities()`, `loans()`, `documents()`.

**Gap real para Social Commerce**: el cliente tiene exactamente **una** columna `phone` y **una** `email`, no una lista de identidades. El vínculo con WhatsApp se hace hoy con un `where('phone', $telefono)->first()` literal (`app/Modules/WhatsApp/Support/ClienteDeWhatsApp.php:69`). No existe ningún concepto de "identidad por canal" (handle de Instagram, PSID de Meta, etc.). Forzar el handle de Instagram dentro de la columna `phone` sería incorrecto y, además, no escala si mañana se añade un tercer canal. **Se necesita una tabla nueva de identidades multicanal** (ver §18) — es el único punto donde el modelo de `Customer` no alcanza tal cual.

---

## 7. Products

`App\Modules\Inventory\Models\Product`. Columnas de precio: solo `cost` y `price` (`decimal(15,2)`), **sin `currency`** (el sistema es mono-moneda, `companies.currency` fija la moneda de la empresa completa) y **sin `discount_price`/precio promocional**. Multi-almacén vive en una tabla `stock` aparte, no en `Product`. `image_path` es singular (sin galería).

`App\Modules\WhatsApp\Support\ProductLookup` ya es el precedente exacto de "buscar producto por texto libre, devolver solo columnas seguras" (excluye `cost` explícitamente, línea 37-40) — patrón a reutilizar tal cual para resolver `keyword → producto` en Social Commerce, en vez de escribir una búsqueda nueva.

Si el prompt maestro quiere manejar "precio promocional" (sección 8 del prompt), **hace falta una migración `add_discount_price_to_products_table`** — no existe hoy. Es un cambio aditivo, de bajo riesgo, y evita crear una tabla de precios paralela.

---

## 8. Opportunities

`App\Modules\CRM\Models\Opportunity` + `Pipeline`/`PipelineStage`. Genérico y reutilizable tal cual para alojar una oportunidad originada en Instagram — el problema no es la forma del modelo, es que **no hay manera de saber de dónde vino**. Ni `Opportunity` ni `Customer` ni `Sale` tienen columna `source`/`channel`/`utm_*` en ningún lado del proyecto (confirmado por grep completo). Es el gap de atribución más barato de cerrar: una columna `source` nullable en `opportunities` (y opcionalmente en la futura tabla de conversaciones de Instagram) resuelve el 90% de lo pedido en la sección 12-13 del prompt maestro sin tocar la forma del modelo.

`CrmService::openOpportunity()` y `moveToStage()` (que dispara el evento `OpportunityStageChanged`, ya es un gancho de automatización/n8n) son el único camino de escritura — Social Commerce debe llamarlos, no tocar el modelo directamente.

---

## 9. Queues

Driver `database` en producción (no `redis`, pese a que Redis está disponible) — consecuencia directa de correr en Vercel sin worker persistente. La cola se drena por una ruta HTTP con cron (`/tareas/drenar-cola`), con un presupuesto duro de ~25s antes de que Vercel corte la función. **Cualquier Job nuevo de Social Commerce debe ser barato** (una llamada HTTP a Zernio, no un lote grande) o corre el riesgo de quedar "atascado" y disparar las alertas de `QueueCheck` del sistema de monitoreo.

Patrón de Job de referencia (`app/Modules/WhatsApp/Jobs/SendWhatsAppMessage.php`): `tries=3`, `backoff=10`, reintento vía excepción relanzada, estado final registrado en `failed()`. No se usa el middleware `RateLimited`/`WithoutOverlapping` de Laravel en ningún job existente — la deduplicación se hace a mano (ver `ResponderAlCliente::llegoOtroDespues()`, un job de `tries=1` a propósito porque un reintento tardío significaría responder fuera de contexto a un cliente real).

---

## 10. Redis

Contenedor disponible (`redis:7-alpine`, puerto 6379) pero usado hoy principalmente para el caché propio de Evolution API, no como driver de cola de Laravel en producción. No hay razón para que Social Commerce dependa de Redis directamente; puede apoyarse en el mismo driver `database` que todo lo demás.

---

## 11. Frontend

Blade + Controller clásico, Alpine.js para interactividad, sin Livewire real pese a estar instalado. Layout único `<x-layouts.admin>`. **La navegación del panel es un único array PHP** dentro de `resources/views/components/layouts/admin.blade.php` (líneas ~27-107), cada entrada `[routeName, label, icon, permission, module]`, filtrado en tiempo real por permiso del usuario y módulo contratado de la empresa. Ya existe una entrada `panel.social` ("Redes sociales") en el grupo "Clientes". Añadir Social Commerce a la navegación es una línea en ese array — pero antes hay que decidir si es una entrada nueva o si se integra dentro de la pantalla de automatizaciones que ya existe (`social-automations.blade.php`), ver §15.

Vistas ya existentes relevantes: `panel/social.blade.php`, `panel/social-automations.blade.php` (+ partial `social-automation-fields.blade.php`), `panel/social-automation-report.blade.php`, `panel/whatsapp.blade.php`. Ninguna tiene selector de producto ni campo de precio hoy.

---

## 12. APIs existentes

`routes/api.php` — versionado bajo `/v1`, controladores en `App\Http\Controllers\Api\V1\*`, todo detrás de `auth:sanctum` salvo `POST /v1/login`. Cada grupo de módulo envuelto en middleware `module:<clave>`, cada ruta individual además con `can:<permiso>`. **Los webhooks entrantes NO están en `api.php`** — viven en `routes/web.php` como rutas públicas bajo `/webhooks/*` (exentas de CSRF por un patrón wildcard en `bootstrap/app.php:80-82`), fuera del grupo `web`/`auth`. Ejemplos: `webhooks.evolution`, `webhooks.polar`, `webhooks.social` (Zernio, con token en la URL). Un nuevo webhook de Social Commerce debe seguir esta misma forma (`/webhooks/<nombre>` o `/webhooks/<nombre>/{token}`).

No hay documentación OpenAPI/Swagger generada automáticamente en el repo — la "documentación" son los propios FormRequest con sus reglas de validación.

---

## 13. Sistema de permisos

**No existen Policies de Laravel en todo el proyecto** (cero archivos `*Policy.php`, ningún `AuthServiceProvider`). El patrón real es: permisos de `spatie/laravel-permission` (con teams = `company_id`) verificados con `can:permiso.nombre` a nivel de ruta y dentro de `FormRequest::authorize()`. El aislamiento por tenant es un mecanismo *separado* (el global scope de §4), no se repite dentro de la comprobación de permiso. Social/WhatsApp ya tienen sus propios permisos (`social.view/publish/connect`, `whatsapp.view/send/connect/templates.manage`), concedidos solo a `owner`/`admin`. Social Commerce probablemente reutiliza `social.*` en vez de inventar un tercer set — otra decisión de producto (§15).

---

## 14. Sistema de logs

Dos mecanismos separados, ambos reutilizables:

1. **Errores/excepciones** (`App\Modules\Core\Monitoring\Errors\ErrorRecorder`): automático — cualquier excepción no capturada, o un `report($e)` explícito, se agrupa por huella, cuenta por empresa/usuario, y alimenta detección de incidentes. No requiere llamada directa en código normal.
2. **Eventos de negocio** (`App\Modules\Core\Models\SystemEvent::registrar(...)`): para algo que se capturó y se manejó (no se relanzó), como "la automatización falló pero seguí". Nunca lanza excepción, seguro de llamar desde un webhook. Ejemplo real en `ResponderAlCliente::handle()`.

Además hay un sistema de **health checks** propio (`app/Modules/Core/Monitoring/Health/`) con un registro central (`HealthRegistry::porOmision()`) donde ya viven chequeos de cola, Redis, BD, Evolution, Polar, IA, correo. Un chequeo nuevo ("token de Instagram por vencer", "Zernio caído") es una clase que implementa `HealthCheck` (3 métodos) añadida a ese array — patrón de una tarde, no de una arquitectura nueva.

---

## 15. Recomendaciones

1. **No dupliques `SocialAutomationController`/`ZernioClient`.** Ya resuelven: selección de publicación, coincidencia por palabra clave con tolerancia a errores, rotación de hasta 5 variantes, DM + respuesta pública, retraso antispam, filtro de seguidores, detección de reglas solapadas y reportes. Construir un motor de reglas paralelo duplicaría exactamente lo que el prompt maestro prohíbe duplicar.
2. **El trabajo real es una capa delgada**: producto+precio → texto de plantilla (con `{{producto}}`/`{{precio}}`, mismo patrón de sustitución que `WaTemplate::render()`) → se guarda como las `dmMessageVariations`/`commentReplyVariations` que ya acepta `StoreAutomationRequest`/`ZernioClient::createAutomation()`. Zernio sigue siendo el motor de coincidencia/antispam; el sistema propio solo decide **qué texto generar** a partir de un producto.
3. **Reutiliza el patrón `ClienteDeWhatsApp`** para crear/enlazar un `Customer` desde una conversación de Instagram, pero primero cierra el gap de §6 (tabla de identidades multicanal) en vez de sobrecargar la columna `phone`.
4. **Reutiliza `Customer`/`Opportunity`/`Pipeline` tal cual**, solo añadiendo una columna `source` nullable a `opportunities` (y quizá a la nueva tabla de conversaciones de Instagram) para resolver la atribución pedida en las secciones 12-13 del prompt.
5. **Copia `polar_webhook_events` + `PolarWebhookHandler::claim()`** para el registro/idempotencia de eventos entrantes de Zernio relacionados con Social Commerce — es el patrón más sólido y ya probado del repo (inserta primero, confía en la restricción única, no en un `exists()` previo).
6. **Copia el patrón de firma de `PolarSignature`** (HMAC + ventana de frescura + rotación de doble clave) en vez del de Zernio a secas — Zernio ya verifica HMAC pero **no tiene protección contra repetición** (replay), y ese es un hueco que no hace falta heredar en la parte nueva.
7. **Enlace a WhatsApp**: no existe generador de enlaces `wa.me/...` en ningún lado del proyecto — es la única pieza puramente nueva y pequeña de la sección 11 del prompt.

---

## 16. Riesgos

1. **Decisión de producto — RESUELTA (2026-09-24): módulo nuevo, vendible por separado.** El dueño del producto confirmó que "Social Commerce" se vende como producto nuevo, no como una funcionalidad más dentro de `social`. Esto implica, cuando se autorice la implementación:
   - Una clave nueva en `App\Modules\Core\Support\ModuleRegistry::MODULES` (p. ej. `social_commerce`), con su etiqueta y descripción de cara al cliente.
   - Un `Plan` (o varios) que la incluya — hoy ningún plan la tendría, así que un cliente existente no la vería hasta que se le asigne, lo cual es el comportamiento correcto para algo que se vende aparte.
   - Probablemente un `polar_product_id` propio si se factura de forma independiente (a confirmar en la Fase 2, no es parte de la Fase 0/1).
   - Su propio set de permisos (`social_commerce.view/manage/...`) en vez de reutilizar `social.*`, ya que son productos distintos con acceso potencialmente distinto.
   - Su propio `ServiceProvider` bajo `app/Modules/SocialCommerce/`, registrado en `bootstrap/providers.php`, en vez de ampliar `App\Modules\Social`. El módulo `Social` (Zernio) se sigue **consumiendo** como dependencia técnica (mismo `ZernioClient`, misma cuenta conectada), pero el código, las rutas, el permiso y la venta son de `SocialCommerce`.
   - Una entrada de navegación propia (no una pestaña dentro de "Redes sociales").
   - Nota: nada de esto se ha implementado todavía — sigue pendiente la autorización explícita para pasar a la Fase 1.
2. **Zernio es un intermediario, no Meta directamente.** La Fase 1 del prompt maestro ("investigar documentación oficial de Meta") debe investigarse **contra la documentación de Zernio primero**, porque es la única superficie que este proyecto toca. Confirmar qué garantías/limitaciones de Meta expone Zernio (rate limits, ventanas de mensajería, App Review, Business Verification) es responsabilidad de Zernio como proveedor, y el equipo no tiene visibilidad directa de eso salvo lo que Zernio documente. Si Zernio deja de operar o cambia condiciones, todo el módulo Social depende de un único proveedor externo — riesgo ya asumido hoy por el proyecto (no nuevo, pero relevante para diseñar Social Commerce encima).
3. **Presupuesto de cola serverless (~25s)**: un job que resuelva "palabra clave → producto → precio → genera 5 variantes → llama a Zernio para actualizar la automatización" debe ser rápido; nada de recorridos masivos de catálogo por webhook.
4. **`products` es mono-moneda y sin precio promocional** — si el negocio realmente necesita precio normal vs. promocional (sección 8 del prompt), hace falta la migración aditiva mencionada en §7 antes de construir la UI de configuración de automatización.
5. **Falta de Policies**: como no hay Policies en el proyecto, cualquier chequeo de propiedad más fino que "pertenece a mi empresa + tengo el permiso" (por ejemplo, "solo el usuario que conectó la cuenta de Instagram puede desconectarla") tendría que añadirse a mano dentro del controlador/FormRequest, no delegarse a un mecanismo ya existente.
6. **Sin IP allowlisting en ningún webhook actual** (ni Evolution, ni Polar, ni Zernio) — solo secreto/firma. Aceptable como está, pero no hay que asumir una capa adicional de red que no existe.

---

## 17. Archivos que deberán modificarse (cuando se autorice la implementación)

- `app/Modules/Core/Support/ModuleRegistry.php` — solo si se decide una clave de módulo nueva (§16.1).
- `resources/views/components/layouts/admin.blade.php` — entrada de navegación.
- `routes/web.php` — grupo de rutas del panel (siguiendo el bloque `module:social` existente, líneas ~805-862) y, si aplica, una ruta de webhook nueva cerca de las líneas 1003-1016.
- `app/Modules/Social/Http/Requests/StoreAutomationRequest.php` y `app/Modules/Social/Services/ZernioClient.php` — para aceptar/generar variantes derivadas de producto+precio, sin romper el contrato actual con Zernio.
- `resources/views/panel/social-automations.blade.php` + `resources/views/partials/social-automation-fields.blade.php` — selector de producto y precio en el formulario.
- `app/Modules/CRM/Models/Opportunity.php` + su migración — columna `source`.
- `app/Modules/Core/Monitoring/Health/HealthRegistry.php` — registrar un chequeo de salud nuevo si aplica.
- `bootstrap/providers.php` — si Social Commerce termina siendo su propio módulo con su propio `ServiceProvider`.

## 18. Archivos nuevos necesarios (propuesta, sujeta a la Fase 2)

- Migración: `customer_channel_identities` (o nombre equivalente) — tabla de identidades multicanal del §6, la única pieza de datos que ningún modelo actual cubre.
- Migración: `instagram_conversations` / `instagram_messages` (espejo de `wa_conversations`/`wa_messages`).
- Migración: `social_post_products` (o `product_instagram_post`) — pivote producto↔publicación.
- Migración: `social_commerce_webhook_events` (clon de `polar_webhook_events`).
- Migración: `add_source_to_opportunities_table`.
- Migración: `add_discount_price_to_products_table` (solo si se confirma que hace falta precio promocional).
- Servicio: generador de texto de plantilla a partir de producto+precio (extensión de la idea de `WaTemplate::render()`).
- Servicio/Builder: enlace `wa.me/...` con texto prellenado.
- Listener/Service: crear `Customer`/`Opportunity` cuando una conversación de Instagram deriva en WhatsApp (siguiendo `ClienteDeWhatsApp`).
- Clase `HealthCheck` nueva, si se decide monitorear la salud de la integración.

## 19. Dependencias necesarias

Ninguna dependencia de Composer/NPM nueva identificada — todo lo requerido (HTTP client, cifrado, colas, eventos, permisos) ya está en el proyecto. Zernio se consume por HTTP simple (`Illuminate\Http\Client`), igual que hoy.

## 20. Plan de implementación (ajustado a lo ya existente)

El plan de 15 fases del prompt maestro asumía partir de cero en la integración con Meta/Instagram. Con lo encontrado en esta auditoría, varias fases cambian de alcance:

- **Fase 1 (investigación de Meta)** se redirige a documentación de **Zernio**, no de Meta directamente (§16.2).
- **Fases 4-5 (conexión OAuth y webhooks de Instagram)** ya existen — no hay nada que construir, solo decidir si Social Commerce reutiliza la conexión/webhook de `social` o necesita uno separado.
- **Fase 6 (listar publicaciones, asociar producto/precio)** es trabajo genuinamente nuevo, acotado a UI + una tabla pivote.
- **Fase 7 (palabras clave)** ya existe casi entera en Zernio/`StoreAutomationRequest` — el trabajo nuevo es alimentarlas desde una regla producto→precio en vez de texto libre.
- **Fase 8 (plantillas)** se apoya en el motor de variantes de Zernio + el patrón `{{var}}` de `WaTemplate`.
- **Fase 9 (automatización de comentarios)** es, en la práctica, "generar los campos que ya acepta `StoreAutomationRequest`" a partir de una regla local.
- **Fase 10 (WhatsApp)** ya está resuelta por el módulo WhatsApp existente — falta el generador de enlace `wa.me` y el paso de atribución.
- **Fase 11 (CRM)** reutiliza `Customer`/`Opportunity`/`Pipeline` tal cual, más la tabla de identidades y la columna `source`.

**Decisión de producto confirmada**: Social Commerce es un módulo nuevo, vendible por separado (ver §16.1). Esto significa que la Fase 2 (arquitectura) deberá diseñar también la clave de módulo, el/los plan(es) que la incluyen y el set de permisos propio — no es un cambio puramente técnico.

Esta auditoría no modifica código. Queda a la espera de autorización explícita para continuar con la Fase 1 (investigación de las capacidades reales de Zernio, dado que es el intermediario hacia Meta que este proyecto ya usa).
