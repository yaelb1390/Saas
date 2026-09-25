# Social Commerce — base de datos

Fecha: 2026-09-25. Referencia de las 9 tablas del módulo (`database/migrations/2026_09_25_1000{00-08}_*.php`). Todas siguen la convención confirmada en la auditoría (Fase 0, §5): `id` bigint autoincremental, sin UUIDs, `company_id` como primera columna de aislamiento.

Ninguna migración de esta lista modifica una tabla de otro módulo. Las referencias a `products`, `customers` y `opportunities` son llaves foráneas de solo lectura.

## `social_commerce_settings`

Una fila por empresa (patrón `wa_bot_settings`/`social_welcome_settings`).

| Columna | Tipo | Nota |
|---|---|---|
| `company_id` | FK único | cascade |
| `is_active` | bool | enciende/apaga el webhook propio |
| `whatsapp_number` | string(20) nullable | E.164 sin `+` |
| `webhook_token` | string(64) único | va en la URL del webhook |
| `webhook_secret` | text, `encrypted` cast | firma HMAC; fuera de `$fillable` |
| `zernio_webhook_id` | string nullable | id devuelto por Zernio al registrar |

## `social_commerce_rules`

La regla palabra clave → producto → precio → plantilla.

| Columna | Tipo | Nota |
|---|---|---|
| `company_id` | FK | cascade |
| `name` | string(80) | |
| `zernio_account_id` | string | id de Zernio, no una fila local |
| `trigger` | string(20) | `comment` \| `story_reply` |
| `zernio_post_id`, `platform_post_id` | string nullable | los dos ids que exige Zernio; ambos null = «en todas» |
| `product_id` | FK `products` | **`restrictOnDelete()`** — no se puede borrar en firme un producto con una regla activa |
| `price_mode` | string(20) | `normal` \| `promotional` (el segundo, sin dato detrás — ver overview) |
| `keywords` | json (array) | tal cual las espera Zernio, no normalizado en tabla aparte |
| `match_mode` | string(20) | `word` \| `exact` \| `contains` |
| `typo_tolerance`, `also_in_dms`, `follow_gate` | bool | |
| `dm_delay_seconds` | int | |
| `button_title`, `button_url` | string nullable | |
| `status` | string(20) | `draft` \| `active` \| `paused` \| `error` — **local**, no es lo mismo que `isActive` de Zernio |
| `zernio_automation_id` | string nullable | **fuera de `$fillable` a propósito** — lo gestiona `RuleSyncService`, no un formulario |
| `last_synced_at`, `sync_error` | timestamp / text nullable | |
| `user_id` | FK `users` nullable | |
| `deleted_at` | soft delete | |

Índices: `[company_id, status]`, `[company_id, product_id]`.

## `social_commerce_rule_templates`

Hasta 6 filas por regla y canal (principal + 5 alternativas — tope real de Zernio, validado en `StoreRuleRequest`, no en la base).

| Columna | Nota |
|---|---|
| `rule_id` | FK cascade |
| `channel` | `dm` \| `public` |
| `body` | texto **con las variables sin resolver** (`{producto}`, `{precio}`…) — se renderiza al sincronizar, nunca se persiste ya rellenado |
| `position` | 0 = principal, 1-5 = alternativas |

## `social_commerce_rule_template_usage`

Registro de qué plantilla se usó, inferido del webhook (no un mecanismo de control — ver `SOCIAL_COMMERCE_TEMPLATES.md`).

`rule_id`/`rule_template_id` son **nullable + `nullOnDelete()`**: el reporte de lo que ya pasó no desaparece si la regla se edita o se borra después (mismo principio que "el recibo no muta" de Ventas).

## `social_commerce_contact_identities`

La pieza que `customers` no tiene (auditoría, §6): une un identificador de Instagram con, opcionalmente, un `Customer`.

| Columna | Nota |
|---|---|
| `customer_id` | FK `customers` nullable, `nullOnDelete()` — nunca se enlaza a ciegas por nombre |
| `channel` | `instagram` (por ahora el único) |
| `external_id` | IGSID/PSID que manda Zernio — estable, a diferencia del @usuario |
| `external_username`, `display_name` | se refrescan si llega un valor nuevo, nunca se borran con uno vacío |
| `first_seen_at`, `last_seen_at` | |

Único: `[company_id, channel, external_id]`.

## `social_commerce_conversations` / `social_commerce_messages`

Espejo de `wa_conversations`/`wa_messages`, pero tabla propia (no toca WhatsApp).

- `conversations.rule_id` (FK nullable, `nullOnDelete()`): de qué regla salió el contacto — la atribución del prompt maestro, sección 12-13.
- `conversations` único: `[company_id, zernio_conversation_id]`.
- `messages` no lleva `softDeletes()` (registro de solo-anexo, igual que `wa_messages`).

## `social_commerce_opportunity_links`

Tabla puente en vez de una columna `source` en `opportunities` (auditoría, §16.1) — así `opportunities` no se entera de que este módulo existe.

| Columna | Nota |
|---|---|
| `opportunity_id` | FK `opportunities`, cascade, **único** (una oportunidad, un origen) |
| `conversation_id`, `rule_id` | FK nullable, `nullOnDelete()` |

## `social_commerce_webhook_events`

Clon exacto de la forma de `polar_webhook_events` (auditoría, §5 y §16): `event_id` único (idempotencia — inserta-primero-y-confía-en-la-restricción, no un `exists()` previo), `result` (`received`/`applied`/`ignored`/`unresolved`), `company_id` **FK nullable con `nullOnDelete()`** (nullable porque al recibir el aviso puede no haberse resuelto la empresa todavía), `payload` json completo para auditar a mano. Vive en la plataforma, no en una empresa — `WebhookEvent` no usa `BelongsToCompany`, igual que `PolarWebhookEvent`.

Zernio no manda un identificador de entrega único a nivel de aviso (a diferencia de la cabecera `webhook-id` de Polar): `event_id` se construye a partir de `message.platformMessageId`, o de un hash de conversación+dirección+texto cuando falta (ver `WebhookController::idDeEvento()`).

## Diagrama de relaciones (resumen)

```
companies ──< social_commerce_settings (1:1)
companies ──< social_commerce_rules ──< social_commerce_rule_templates
                     │                       │
                     │                       └──< social_commerce_rule_template_usage
                     │
products (Inventory) ─── restrictOnDelete ──> social_commerce_rules

social_commerce_contact_identities ──< social_commerce_conversations ──< social_commerce_messages
        │                                       │
customers (CRM) <── nullOnDelete ────────────────┘

social_commerce_conversations ──< social_commerce_opportunity_links >── opportunities (CRM)
social_commerce_rules ─────────< social_commerce_opportunity_links
```
