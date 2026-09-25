# Social Commerce — conectar una cuenta (guía operativa)

Fecha: 2026-09-25. Guía práctica de "cómo se activa"; el "qué permite Zernio y con qué evidencia" está en `SOCIAL_COMMERCE_META_RESEARCH.md`, no aquí.

## Requisito previo

Una cuenta de Zernio propia del cliente (no de la plataforma — cada empresa contrata Zernio por su cuenta, ver `ZernioClient.php:18-22`). Sin eso no hay nada que conectar.

## Pasos, desde el panel

1. **Ajustes de Social Commerce** (`panel.social-commerce.settings`) → pegar la clave de Zernio (`sk_` + 64 caracteres hexadecimales). Se guarda en `companies.social_api_key`, cifrada — la **misma columna** que usa el módulo `Social`, así que si la empresa ya tenía Redes sociales conectado, Social Commerce ya está "conectado" sin hacer nada más.
2. **Conectar Instagram/Facebook**: botón que redirige a `ZernioClient::connectUrl()`, que a su vez lleva al flujo de autorización de la propia red. Al volver, la cuenta aparece en la lista.
3. **Número de WhatsApp**: en la misma pantalla, formato E.164 sin el `+`. Alimenta la variable `{url_whatsapp}` y el botón de las plantillas.
4. **Encender "Recibir avisos de Instagram"**: activa `social_commerce_settings.is_active` y dispara `WebhookRegistrar::sincronizar()`, que da de alta el webhook propio en Zernio (`registrarWebhook()`) apuntando a `route('webhooks.social-commerce', $token)`.

Sin el paso 4, las reglas siguen contestando en Instagram (eso lo hace Zernio, no depende del webhook), pero **no se registran conversaciones ni se enlaza nada al CRM** — el webhook es la única puerta de entrada de esos datos.

## Cómo verificar que quedó bien

- En Ajustes, la cuenta conectada debe aparecer listada (nombre + red).
- Crear una regla de prueba y usar **Modo prueba** (`panel.social-commerce.sandbox`) para confirmar que el producto/precio/plantilla resuelven bien — esto no llama a Zernio, solo prueba la lógica local.
- Comentar de verdad en la publicación (o en cualquiera, si la regla no está atada a una concreta) y comprobar que aparece en **Conversaciones**. Si no aparece, ver `SOCIAL_COMMERCE_WEBHOOKS.md` § Diagnóstico.

## Qué pasa si se desconecta la clave

`company->social_api_key = null` → `ZernioClient::isConfigured()` devuelve `false` → cualquier intento de sincronizar una regla falla con `SocialException::sinClave()`, capturado por `RuleSyncService` como `sync_error`, la regla queda en estado `error`. El webhook propio sigue registrado en Zernio hasta que se apague manualmente desde Ajustes (no se borra solo al quitar la clave).

## Dominios y firewalls

`ZernioClient` habla con `https://api.zernio.com` — si el servidor de producción tiene salida HTTP restringida, ese dominio necesita estar permitido (mismo requisito que ya tiene el módulo `Social`, no es nuevo).

El webhook propio necesita ser alcanzable **desde internet** (Zernio corre en la nube). `WebhookRegistrar` usa la misma comprobación que `ZernioWebhookRegistrar::alcanzable()` del módulo Social para rechazar registrar una URL de `localhost`/red privada antes de que falle en silencio.
