# Social Commerce — visión general

Fecha: 2026-09-25. Punto de entrada a la documentación del módulo; el resto de documentos de esta carpeta profundizan en cada pieza.

## Qué es

Un módulo nuevo, vendido por separado (`social_commerce` en `ModuleRegistry`), que contesta preguntas de precio en los comentarios de Instagram usando reglas locales (palabra clave → producto → precio → plantilla), sin IA, y alimenta al CRM con los contactos que salen de ahí.

**No es una integración nueva con Meta.** El proyecto ya habla con Instagram a través de un proveedor externo llamado **Zernio** (módulo `Social`, `App\Modules\Social\Services\ZernioClient`), que ya trae un motor de automatización de comentarios completo (coincidencia por palabra clave, rotación de hasta 6 textos, retraso antispam, DM + respuesta pública). Social Commerce **reutiliza ese motor** — nunca lo duplica ni le añade una segunda vía hacia Meta — y añade encima lo que Zernio no tiene: producto, precio, atribución al CRM y un panel propio.

Léase primero `SOCIAL_COMMERCE_AUDIT.md` (qué existía antes de este módulo) y `SOCIAL_COMMERCE_META_RESEARCH.md` (qué permite Zernio de verdad, con evidencia) si hace falta el porqué de cada decisión — este documento asume que ya se leyeron y solo resume.

## Mapa del módulo

```
app/Modules/SocialCommerce/
├── Enums/          RuleStatus, RotationStrategy, PriceMode, TemplateChannel, MessageDirection
├── Models/          Settings, Rule, RuleTemplate, RuleTemplateUsage, ContactIdentity,
│                    Conversation, Message, OpportunityLink, WebhookEvent
├── Services/        TemplateRenderer, RuleSyncService, WebhookRegistrar, WebhookSignature,
│                    ContactIdentityResolver, TemplateUsageRecorder, WebhookEventProcessor,
│                    WhatsAppLinkBuilder, KeywordMatcher
├── Support/         ZernioAutomationPayload
├── Events/          LeadCaptured
├── Http/
│   ├── Controllers/ RuleController, SettingsController, WebhookController,
│   │                ConversationController, SandboxController, DashboardController
│   └── Requests/    StoreRuleRequest, StoreSettingsRequest
└── Providers/       SocialCommerceServiceProvider
```

Nada de esto vive dentro de `app/Modules/Social`, `WhatsApp`, `CRM` ni `Inventory` — son módulos aparte, solo referenciados (arquitectura, sección 1).

## Las pantallas

| Pantalla | Ruta | Qué hace |
|---|---|---|
| Reglas | `panel.social-commerce.index` | Listar/crear/editar/pausar/borrar reglas |
| Conversaciones | `panel.social-commerce.conversations.index` | Ver quién ha escrito, enlazar con un cliente, abrir una oportunidad |
| Modo prueba | `panel.social-commerce.sandbox` | Simular un comentario sin publicar nada |
| Dashboard | `panel.social-commerce.dashboard` | Números: reglas activas, conversaciones, mensajes, oportunidades |
| Ajustes | `panel.social-commerce.settings` | Conectar Zernio, número de WhatsApp, encender el webhook |

## El flujo, de punta a punta

```
Comerciante: producto + palabras clave + plantillas
       │
RuleSyncService → arma el payload con TemplateRenderer → ZernioClient::createAutomation()
       │
   [Zernio ejecuta la automatización — matching y envío son suyos, no nuestros]
       │
Comentario real → Zernio responde → dispara `message.received` a NUESTRO webhook propio
       │
WebhookController → firma + idempotencia → WebhookEventProcessor
       │
   entrante → ContactIdentityResolver → Conversation/Message → evento LeadCaptured
   saliente → TemplateUsageRecorder (reporte de qué plantilla se usó, no control)
       │
Conversación en el panel → enlazar cliente → CrmService::openOpportunity() → OpportunityLink
```

## Lo que NO se puede hacer, a propósito

- **Rotación secuencial o "menos usada recientemente"**: Zernio elige la variante al azar, en su propio servidor, sin que nuestra aplicación intervenga. No hay API para pedir otra cosa. Ver `RotationStrategy` y `SOCIAL_COMMERCE_TEMPLATES.md`.
- **"Instagram → WhatsApp" como métrica**: el botón es un enlace `wa.me` directo, no pasa por nuestro servidor. No hay clic que contar.
- **Precio promocional**: `products` no tiene esa columna; añadirla tocaría el módulo Inventory, fuera del alcance de esta entrega. `PriceMode::Promotional` existe declarado pero sin dato detrás.

## Decisiones que cuesta reconstruir si no se leen aquí

1. **Webhook propio, distinto del de `Social`.** Cada uno tiene su token/secreto (`social_commerce_settings` vs `social_welcome_settings`), para que una empresa pueda tener Social Commerce sin `social` contratado.
2. **`social_commerce_opportunity_links` en vez de una columna `source` en `opportunities`.** Evita tocar el módulo CRM.
3. **El webhook procesa en el acto, sin Job en cola.** Todo lo que hace es escritura local (sin llamadas externas), y la cola de este proyecto se drena por cron en producción — encolarlo solo añadiría minutos de espera sin ganar nada. Ver `RuleSyncService`/`WebhookController` para lo que sí necesita reintentos (llamar a Zernio).
4. **`RoleProvisioner` tiene que llevar los permisos nuevos, no solo la migración de datos.** La migración solo repara empresas que ya existían; las que se creen después reciben sus permisos de `RoleProvisioner::PERMISSIONS`/`ROLES`. Se descubrió con una prueba HTTP real, no a simple vista.
