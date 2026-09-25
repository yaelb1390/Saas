# Arquitectura — Módulo Social Commerce (FASE 2)

Fecha: 2026-09-24. Basado en `SOCIAL_COMMERCE_AUDIT.md` (Fase 0) y `SOCIAL_COMMERCE_META_RESEARCH.md` (Fase 1).

Decisión de negocio ya tomada por el dueño del producto: **Social Commerce es un módulo nuevo, vendible por separado**, no una funcionalidad dentro de `social`.

Restricción explícita del dueño del producto: **no tocar el módulo ya existente**. Esta arquitectura se diseña para que ningún archivo de `app/Modules/Social`, `app/Modules/WhatsApp`, `app/Modules/CRM` ni `app/Modules/Inventory` se modifique. Todo lo nuevo vive en `app/Modules/SocialCommerce`, con tablas propias que **referencian** (FK de solo lectura/enlace) las tablas existentes, nunca las alteran.

---

## 1. Qué se reutiliza y cómo (sin tocar el archivo)

| Se reutiliza | Cómo, sin modificarlo |
|---|---|
| `App\Modules\Social\Services\ZernioClient` | Se instancia igual que hace `Social` (`new ZernioClient($company)`), para crear/editar/borrar automatizaciones y registrar un webhook propio. Es una clase de servicio sin estado por empresa — usarla desde otro módulo no requiere tocarla. |
| `App\Modules\Social\Enums\{KeywordMatch,AutomationTrigger,SocialPlatform}` | Se importan tal cual — son enums puros, sin dependencias de base de datos. |
| `companies.social_api_key` | Se lee vía la relación `Company` ya existente; Social Commerce no gestiona su propia clave, usa la misma cuenta de Zernio que la empresa ya conectó (ver §2, esto es intencional). |
| `App\Modules\Inventory\Models\Product` | Se referencia por `product_id` (FK) desde las tablas nuevas. Nunca se le añade una columna. |
| `App\Modules\CRM\Models\Customer` / `Opportunity` / `Pipeline` | Se usan sus métodos públicos ya existentes (`Customer::create()`, `CrmService::openOpportunity()`) para crear registros. Nunca se les añade una columna ni se modifica su migración. |
| `App\Modules\Core\Tenancy\BelongsToCompany` / `HasCompany` | Se usa igual que en todo el proyecto — es el contrato estándar, no pertenece a ningún módulo de negocio. |
| Patrón de firma de webhook de Polar (`PolarSignature`) | Se **copia el patrón** (HMAC + ventana de frescura + rotación de doble clave) dentro de una clase propia de Social Commerce — no se importa `PolarSignature` directamente porque acoplaría un módulo de negocio a otro; se reimplementa igual, es una utilidad de ~40 líneas. |
| Patrón `polar_webhook_events` | Se **clona la forma de la tabla**, no la tabla: `social_commerce_webhook_events` nueva, mismo diseño (evento único, resultado, payload json, sin FK a `company_id`). |

**Consecuencia de esta decisión**: para "conectar Instagram", una empresa que compre Social Commerce sin tener `social` sigue pudiendo hacerlo — Social Commerce tiene su propia pantalla de conexión que llama a `ZernioClient::connectUrl()`/`accounts()` igual que hace `Social`, pero bajo su propio permiso. Si la empresa además tiene `social` activo, ambos módulos leen la misma cuenta conectada (es la misma cuenta de Zernio, es correcto que coincidan) sin ningún acoplamiento de código entre ellos.

---

## 2. Decisión: cuenta de Zernio compartida, no una integración nueva

Social Commerce **no pide una clave de Zernio propia**. Usa `companies.social_api_key`, la misma columna que ya existe (Fase 0, §4). Alternativas consideradas y descartadas:

- Pedir una segunda clave de Zernio solo para Social Commerce → **descartada**: sería la misma cuenta de Instagram del cliente, pedir la clave dos veces confundiría y podría llevar a que alguien conecte una cuenta distinta por error, rompiendo la premisa de "un negocio, una cuenta".
- Duplicar la lógica de conexión dentro de `SocialCommerce` copiando literalmente `ZernioClient` → **descartada**: es exactamente la duplicación que el prompt maestro prohíbe.

**Decisión final**: `SocialCommerce` depende en tiempo de ejecución de la clase `ZernioClient` (una dependencia de composición, no de módulo — se resuelve con `app(ZernioClient::class)` pasando la `Company` actual, igual que hace `SocialAutomationController`). No hay acoplamiento a rutas, controladores ni tablas de `Social`.

---

## 3. Estructura de carpetas

```
app/Modules/SocialCommerce/
├── Enums/
│   ├── RuleStatus.php            (draft|active|paused|error)
│   ├── RotationStrategy.php      (random — única disponible, ver investigación Fase 1)
│   ├── PriceMode.php             (normal|promotional — promotional queda reservado, ver §7)
│   └── TemplateChannel.php       (dm|public)
├── Models/
│   ├── Rule.php
│   ├── RuleTemplate.php
│   ├── RuleTemplateUsage.php
│   ├── Settings.php
│   ├── ContactIdentity.php
│   ├── Conversation.php
│   ├── Message.php
│   ├── WebhookEvent.php
│   └── OpportunityLink.php
├── Services/
│   ├── TemplateRenderer.php       (variables {producto}/{precio}/…)
│   ├── KeywordMatcher.php         (réplica local del algoritmo de Zernio, solo para el sandbox)
│   ├── RuleSyncService.php        (Rule+Templates → payload Zernio → ZernioClient)
│   ├── ContactIdentityResolver.php
│   ├── TemplateUsageRecorder.php
│   ├── WhatsAppLinkBuilder.php
│   └── WebhookSignature.php       (patrón Polar, copiado no importado)
├── Jobs/
│   ├── SyncRuleWithZernio.php
│   └── ProcessWebhookEvent.php
├── Events/
│   └── LeadCaptured.php           (punto de enganche n8n, mismo patrón que WhatsAppMessageReceived)
├── Listeners/
│   └── (ninguno en v1 — LeadCaptured no tiene consumidor interno todavía, solo el gancho externo)
├── Http/
│   ├── Controllers/
│   │   ├── RuleController.php
│   │   ├── SettingsController.php
│   │   ├── SandboxController.php
│   │   └── WebhookController.php
│   └── Requests/
│       ├── StoreRuleRequest.php
│       └── StoreSettingsRequest.php
├── Providers/
│   └── SocialCommerceServiceProvider.php
└── Support/
    └── ZernioAutomationPayload.php  (construye el array que espera ZernioClient::createAutomation())
```

Sin carpeta `Policies/` — el proyecto no usa Policies de Laravel en ningún módulo (Fase 0, §13); autorización vía `can:social_commerce.*` en rutas/FormRequests, igual que todos los demás.

---

## 4. Modelo de datos (detalle en `SOCIAL_COMMERCE_DATABASE.md`, Fase 3)

Ocho tablas nuevas, todas `company_id` + `BelongsToCompany`, ninguna modifica una tabla existente:

1. **`social_commerce_settings`** — una fila por empresa (espejo de `wa_bot_settings`/`social_welcome_settings`): número de WhatsApp de destino, plantilla del mensaje inicial de WhatsApp, token/secreto del webhook propio, id del webhook registrado en Zernio.
2. **`social_commerce_rules`** — la regla palabra-clave→producto→precio→plantilla. Referencia `product_id` (FK a `products`, `restrictOnDelete` — no se puede borrar un producto con una regla activa, mismo criterio que `sale_items.product_id`).
3. **`social_commerce_rule_templates`** — hasta 6 plantillas por regla y canal (principal + 5 alternativas, tope real de Zernio).
4. **`social_commerce_rule_template_usage`** — registro de qué plantilla se usó, inferido de forma pasiva del webhook (ver Fase 1: Zernio no permite controlar la rotación, solo observarla después del hecho).
5. **`social_commerce_contact_identities`** — la pieza que el CRM no tiene hoy (Fase 0, §6): une un `external_id` de Instagram con, opcionalmente, un `Customer` ya existente.
6. **`social_commerce_conversations`** / 7. **`social_commerce_messages`** — espejo de `wa_conversations`/`wa_messages`, pero para Instagram, con `rule_id` para saber qué automatización originó el contacto (la atribución que el prompt maestro pide en su sección 12-13).
8. **`social_commerce_opportunity_links`** — en vez de añadir una columna `source` a `opportunities` (que sí tocaría el módulo CRM), esta tabla puente guarda `opportunity_id + conversation_id + rule_id`. Responde "¿de qué automatización salió esta oportunidad?" sin tocar la tabla `opportunities`.
9. **`social_commerce_webhook_events`** — idempotencia de eventos entrantes, clon de `polar_webhook_events`.

---

## 5. Flujo de automatización (ajustado a lo que Zernio permite de verdad)

```
Comerciante configura en el panel:
  Producto (existente, de Inventory) + qué precio usar (normal)
       │
  Palabras clave + modo de coincidencia
       │
  Plantillas de respuesta pública / DM (con {producto}/{precio}/…)
       │
       ▼
RuleSyncService::sync($rule)
  → TemplateRenderer genera hasta 6 textos por canal a partir del producto
  → ZernioAutomationPayload arma el array (mismo formato que StoreAutomationRequest::paraZernio())
  → ZernioClient::createAutomation()/updateAutomation()
  → guarda zernio_automation_id + last_synced_at (o sync_error si falla)
       │
       ▼
   [Zernio, fuera de nuestro control]
   Comentario real llega → Zernio compara palabra clave → Zernio ELIGE una variante al
   azar → Zernio responde (público + DM) → Zernio abre/usa la conversación
       │
       ▼
Zernio dispara `message.received` a NUESTRO webhook propio (no al de Social)
       │
       ▼
SocialCommerceWebhookController → verifica firma+frescura → idempotencia
  (social_commerce_webhook_events) → despacha ProcessWebhookEvent
       │
       ├─ entrante (direction=incoming) → ContactIdentityResolver (crea/liga Customer)
       │    → Conversation/Message → evento LeadCaptured (gancho n8n)
       │
       └─ saliente (direction=outgoing) → TemplateUsageRecorder intenta emparejar el
            texto contra las plantillas configuradas → social_commerce_rule_template_usage
```

**Lo que NO existe, documentado igual que exige el prompt maestro:** no hay forma de que nuestra aplicación decida en tiempo real qué variante responde Zernio, así que "rotación secuencial" y "menos usada recientemente" (sección 3 del prompt maestro) se muestran en la UI como **no disponibles bajo la API oficial** en vez de simularse. Ver `RotationStrategy` enum — de momento un solo caso, `Random`, con un comentario que explica por qué no hay más.

---

## 6. Multi-tenant, seguridad, colas — heredado sin inventar nada nuevo

- Todas las tablas usan `BelongsToCompany`/`HasCompany` (Fase 0 §4).
- Credencial: ninguna propia — se apoya en `companies.social_api_key` ya cifrada.
- `social_commerce_settings.webhook_secret`: `encrypted` cast, excluido de `$fillable` (mismo criterio que `SocialWelcomeSetting::secret`, Fase 0 §4).
- Firma del webhook propio: HMAC-SHA256 + ventana de frescura (patrón Polar, la variante MÁS estricta de las tres que ya existen — Fase 0 §16 lo recomendaba explícitamente sobre el patrón de Zernio a secas).
- Idempotencia: `social_commerce_webhook_events.event_id` único, inserta-primero-y-confía-en-la-restricción (patrón `PolarWebhookHandler::claim()`).
- Jobs: `tries`/`backoff` explícitos, inyectan `CurrentCompany` y hacen `->set()` primero (patrón `SendWhatsAppMessage`), pensados para caber en el presupuesto de ~25s de la cola serverless (Fase 0 §9).
- Permisos nuevos, sin tocar `config/permission.php` (es configuración global del paquete, no de un módulo): `social_commerce.view`, `social_commerce.manage`, `social_commerce.connect` — sembrados por una migración de datos (`grant_social_commerce_permissions...`), mismo patrón que `2026_08_16_120100` hizo para `social.*`.
- Auditoría: `Settings` usa `Auditable` (guarda credenciales/config), `Rule` no (es negocio de alto volumen, mismo criterio que el resto de modelos de negocio — Fase 0 §14).

---

## 7. Lo que se deja fuera de v1 (y por qué)

- **Precio promocional (`discount_price`)**: requeriría una migración `add_discount_price_to_products_table` que sí toca el módulo Inventory — se deja fuera de esta primera entrega por la restricción de "no tocar lo existente". `PriceMode::Promotional` queda declarado en el enum pero sin dato que lo respalde; se documenta como pendiente, no se inventa un campo paralelo.
- **Rotación secuencial / menos usada recientemente**: no disponible bajo la API oficial (Fase 1). No se simula.
- **Chequeo de salud en `HealthRegistry`**: los chequeos existentes son de la plataforma completa (BD, Redis, cola, Evolution, Polar, IA, correo), no por empresa. Un fallo de sincronización de una regla concreta es del negocio de una empresa, no de la plataforma — se resuelve con `SystemEvent::registrar()` + un panel de errores dentro del propio módulo (sección 33 del prompt maestro), no con un `HealthCheck` nuevo.
- **Dashboard de analítica, modo sandbox completo, CRUD de plantillas globales reutilizables entre reglas**: quedan para una fase posterior — no se construye todo el sistema en una sola entrega (regla explícita de CLAUDE.md).

---

Esta arquitectura no ha creado todavía ninguna migración ni modelo. Sirve de base para la Fase 3 (base de datos), que se implementa a continuación en esta misma entrega, dado que el dueño del producto ya autorizó "crear el módulo nuevo".

## 8. Ajustes tras implementar la Fase 4 (correcciones reales, no solo plan)

- **El webhook propio NO usa `ProcessWebhookEvent` como Job en cola.** Al implementarlo se confirmó que, a diferencia de `ResponderAlCliente` (que llama a un proveedor de IA), procesar `message.received` aquí es solo escritura local (identidad de contacto, conversación, mensaje, uso de plantilla) — cero llamadas externas. Encolarlo solo añadiría la espera del drenaje por cron (Fase 0, sección 9) sin ganar nada, así que `WebhookController` llama a `WebhookEventProcessor` directamente, después de reclamar la idempotencia.
- **La firma del webhook propio (`WebhookSignature`) es solo HMAC, sin ventana de frescura.** Se investigó y Zernio no manda una cabecera de sello de tiempo (a diferencia de Polar): no hay nada que comprobar sin inventar un dato que la API no ofrece. La protección contra reintentos duplicados la da la idempotencia de `social_commerce_webhook_events`, no la firma.
- **Bug real encontrado y corregido en `RuleSyncService::sync()`**: el payload se construía antes de decidir si la regla debía quedar activa, así que una regla nueva (`draft`) se mandaría a Zernio como `isActive: false` — y `ZernioClient::createAutomation()` respeta esa intención con un PATCH adicional que la apaga. Se corrigió fijando el estado ANTES de construir el payload. Cubierto por una prueba que falla si se repite el error.
