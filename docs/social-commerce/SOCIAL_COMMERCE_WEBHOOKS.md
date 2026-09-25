# Social Commerce — webhooks

Fecha: 2026-09-25.

## Endpoint

```
POST /webhooks/social-commerce/{token}
```

Nombre de ruta: `webhooks.social-commerce`. Registrado en `routes/web.php`, fuera de cualquier grupo `auth`/`web` de sesión — exento de CSRF por el patrón `webhooks/*` de `bootstrap/app.php` (ver auditoría, §12).

**Distinto del webhook de `Social`** (`/webhooks/redes/{token}`, `webhooks.social`). No comparten token, secreto, ni tabla de idempotencia. Ver arquitectura, sección 2, para el porqué.

## Verificación (en orden)

1. **Tenant**: `token` en la URL → `Settings::withoutGlobalScopes()->where('webhook_token', $token)->first()`. Nunca se confía en nada del cuerpo para esto.
   - Token inexistente → 401 genérico.
2. **Firma**: cabecera `X-Zernio-Signature`, HMAC-SHA256 del **cuerpo crudo** con `social_commerce_settings.webhook_secret` (`WebhookSignature::verify()`). Acepta el prefijo opcional `sha256=`. Comparación en tiempo constante (`hash_equals`).
   - Firma ausente o incorrecta → **mismo 401 genérico** que el token inexistente (no distinguir los dos casos evita que alguien sondee direcciones).
3. **Idempotencia**: se calcula un `event_id` (ver más abajo) y se intenta `WebhookEvent::create(...)`. Si la restricción única de la base rechaza el insert (`QueryException`), es un reintento — se responde `200 {"resultado":"repetido"}` sin reprocesar nada. El patrón es "inserta primero, confía en la restricción", no un `exists()` previo (condición de carrera bajo entregas simultáneas — mismo razonamiento que `PolarWebhookHandler::claim()`).
4. Solo entonces se llama a `WebhookEventProcessor::handle()`.

Un fallo **nuestro** dentro del paso 4 nunca devuelve 500: se captura, se registra (`report($e)` + el evento se marca `unresolved` con el motivo en `note`), y se responde 200 igualmente — Zernio reintenta lo que no recibe 2xx, y un error que se repita convertiría un bug en un bucle de reintentos.

## `event_id`: cómo se construye

Zernio **no** manda un identificador de entrega único a nivel del aviso (a diferencia de la cabecera `webhook-id` de Polar). Se usa, en orden de preferencia:

1. `msg:{message.platformMessageId}` si viene.
2. `hash:{sha256(conversationId|direction|text)}` si no.

Esto es una aproximación razonable con lo que la API ofrece, no un identificador garantizado único por Zernio — documentado así a propósito, sin fingir una garantía que no existe.

## Payload que de verdad se lee

`WebhookEventProcessor` solo mira estos campos (todo lo demás del payload de Zernio se ignora):

```
message.platform          instagram | facebook | whatsapp (solo instagram/facebook se procesan)
message.direction         incoming | outgoing
message.text
message.conversationId
message.platformMessageId
message.sender.id         identificador estable (IGSID/PSID)
message.sender.username
message.sender.name
message.createdAt / message.sentAt
account.id / account._id
```

## Qué hace con cada dirección

- **`incoming`**: `ContactIdentityResolver` (busca/crea `ContactIdentity` por `[company_id, channel, external_id]`) → busca/crea `Conversation` por `zernio_conversation_id` → crea `Message` → si la conversación es nueva, dispara `LeadCaptured` (gancho para n8n, sin consumidor interno todavía).
- **`outgoing`**: `TemplateUsageRecorder` compara el texto contra las plantillas renderizadas de las reglas activas de la empresa; si coincide alguna, registra una fila en `social_commerce_rule_template_usage`. Esto es un **reporte de mejor esfuerzo**, no un mecanismo de control — ver `SOCIAL_COMMERCE_TEMPLATES.md`. Solo fiable para el mensaje privado: una respuesta pública bajo un comentario no necesariamente pasa por este mismo tipo de aviso.
- **Plataforma no soportada** (`whatsapp`, u otra): se marca `applied` con la nota "plataforma no soportada" y no se hace nada más — no es un error, es una decisión.

## Procesamiento síncrono, no en cola

A diferencia de `ResponderAlCliente` (WhatsApp), que encola porque llama a un proveedor de IA, este webhook **procesa en el acto**, dentro de la misma petición HTTP: todo lo que hace es escritura local, sin llamadas externas, y la cola de este proyecto se drena por cron en producción (auditoría, §9) — encolarlo solo añadiría minutos de espera sin ningún beneficio.

## Diagnóstico

| Síntoma | Causa probable |
|---|---|
| Nada aparece en Conversaciones | `social_commerce_settings.is_active` apagado, o el webhook nunca se registró en Zernio (`zernio_webhook_id` null) |
| 401 en el registro de `SystemEvent` (`type: webhook.rejected`) | Token o firma inválidos — revisar que el secreto no se haya regenerado sin volver a registrar el webhook en Zernio |
| El mismo comentario genera dos conversaciones | No debería pasar: revisar `event_id` (¿el aviso trae `platformMessageId` realmente distinto, o es un reenvío con datos ligeramente distintos que rompe el hash?) |
| `WebhookEvent.result = unresolved` | Ver `note` en esa fila — ahí queda el mensaje de la excepción que se atrapó |
