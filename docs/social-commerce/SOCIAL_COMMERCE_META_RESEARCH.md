# Investigación de capacidades oficiales (FASE 1)

Fecha: 2026-09-24.

## Por qué esto investiga Zernio y no la API de Meta directamente

La Fase 0 encontró que este proyecto **no habla con la API de Meta/Instagram directamente**: todo pasa por Zernio (`https://api.zernio.com`), un proveedor SaaS externo contratado por cada empresa cliente con su propia clave. `App\Modules\Social\Services\ZernioClient` es el único punto de contacto real que existe en el código, y sus comentarios documentan comportamientos **verificados contra la API en producción** (no contra su manual, que en varios puntos se equivoca — ver evidencia abajo). Esta investigación se basa en esa fuente, que es la única disponible y verificable dentro del repositorio: no hay credenciales de Meta Business/App Review propias de esta plataforma, ni se van a crear para Social Commerce (reutiliza la cuenta de Zernio que la empresa ya conectó en el módulo `Social`).

Todo lo de aquí abajo tiene como fuente `app/Modules/Social/Services/ZernioClient.php`, `app/Modules/Social/Http/Requests/StoreAutomationRequest.php`, `app/Modules/Social/Enums/{KeywordMatch,AutomationTrigger,SocialPlatform}.php` y `app/Modules/Social/Http/Controllers/ZernioWebhookController.php`, leídos línea por línea. Donde el propio código dice "comprobado contra la API real" se marca como tal; donde algo no está confirmado en ningún archivo, se marca **NO VERIFICADO**, tal como exige el prompt maestro.

---

## Tabla de capacidades

| Función | Disponible | Endpoint (Zernio) | Permisos/credencial | Requisitos | Limitaciones | Fuente |
|---|---|---|---|---|---|---|
| Conectar cuenta de Instagram | SÍ (ya en uso) | `GET /v1/connect/instagram` | Clave de API por empresa (`companies.social_api_key`) | `profileId` es obligatorio aunque el manual de Zernio lo da por opcional | Enlace de autorización caduca, se pide al momento | `ZernioClient::connectUrl()` L52-67, comentario L48-50 |
| Listar cuentas conectadas | SÍ | `GET /v1/accounts` | igual | — | Una cuenta caducada no da error al publicar: se traga el post silenciosamente, por eso se expone `necesita_reconectar` | `ZernioClient::accounts()` L106-131, comentario L125-126 |
| Listar publicaciones ya hechas por Zernio | SÍ | `GET /v1/posts` | igual | — | Solo conoce lo publicado a través de Zernio | `ZernioClient::publishedPosts()` L344-378 |
| Traer publicaciones hechas fuera de Zernio (desde el móvil) | SÍ | `POST /v1/posts/sync-external` | igual | `accountId` | Solo lee, no publica; puede tardar (timeout 60s) | `ZernioClient::syncExternalPosts()` L389-403, comentario L383-385 |
| Publicar / programar contenido | SÍ (ya en uso, no lo usa Social Commerce) | `POST /v1/posts` | igual | Imagen/video obligatorios en Instagram | `scheduledFor` exige zona horaria explícita | `ZernioClient::publish()` L433-469 |
| **Automatización de comentarios por palabra clave** | **SÍ — es el corazón de Social Commerce** | `POST/PATCH/DELETE/GET /v1/comment-automations[/{id}]` | igual | `profileId`, `accountId`, `keywords`, `dmMessage` | Ver desglose completo abajo | `ZernioClient::{automations,createAutomation,updateAutomation,deleteAutomation}()` |
| Registro de disparos de una automatización (logs + "misses") | SÍ | `GET /v1/comment-automations/{id}/logs` | igual | — | Tope de 200 por página; `misses` es la única forma de saber qué palabra no está cazando nada | `ZernioClient::automationLogs()` L299-330 |
| Enviar mensaje privado dentro de una conversación existente | SÍ, con condición | `POST /v1/inbox/conversations/{id}/messages` | igual | La conversación debe existir ya | **Solo se puede contestar, nunca escribir primero** — Instagram exige que la ventana la abra la persona; no hay forma de iniciar un DM desde cero | `ZernioClient::enviarMensaje()` L513-531, comentario L505-507 |
| Webhook de eventos entrantes | SÍ, un único tipo útil | `POST /v1/webhooks/settings` (alta), evento `message.received` | Secreto propio por webhook (HMAC) | — | Solo existen dos tipos de evento conocidos en este código: `message.received` (útil) y `conversation.started` (descartado a propósito porque no dice quién la abrió, y las respuestas automáticas también la disparan) | `ZernioClient::registrarWebhook()` L589-610, comentario L595-597 |
| Presignar subida de imagen | SÍ | `POST /v1/media/presign` | igual | Campo `filename` en minúscula (el manual decía `fileName`, la API lo rechaza) | — | `ZernioClient::presignMedia()` L480-500, comentario L483-485 |
| Desconectar cuenta | SÍ | `DELETE /v1/accounts/{id}` | igual | — | No revoca nada en Meta, solo aquí | `ZernioClient::desconectarCuenta()` L565-580 |

### Desglose de `comment-automations` (lo que de verdad usa Social Commerce)

| Campo | Confirmado | Detalle |
|---|---|---|
| Coincidencia de palabra clave | SÍ | 3 modos: `word` (palabra suelta, con tolerancia a errores tipográficos opcional — **por omisión de este proyecto**, no de la API), `exact` (comentario exacto), `contains` (en cualquier parte, valor por omisión de la API pero **evitado** aquí porque genera falsos positivos: "precio" dispara con "aprecio") |
| Disparador | SÍ | `comment` (comentario en una publicación) o `story_reply` (respuesta a una historia). En historias, "responder también por privado" está **siempre activo de fábrica** — la API lo rechaza si se pide explícitamente (400: *"alsoMatchInDms is not available on story_reply automations"*) |
| Respuesta pública + DM privado | SÍ | Ambas configurables; el DM tiene 1000 caracteres (640 si lleva botón), la respuesta pública 500 |
| **Rotación de variantes** | SÍ, pero **con una limitación dura** | Hasta 5 variantes alternativas + la principal (6 en total). **Zernio elige UNA AL AZAR en su propio servidor, en el momento de responder, por separado para el DM y para el comentario público** (`ZernioClient` no participa en esa elección — comentario explícito en `StoreAutomationRequest.php:222-223`: *"Zernio elige una al azar entre la principal y sus alternativas, y lo hace por separado para el privado y para el comentario"*). **No hay ningún parámetro para pedir rotación secuencial ni "la menos usada recientemente" — esa selección no es controlable desde fuera de Zernio.** |
| Botón con URL | SÍ | Un único botón tipo `url`, con título (máx. 20 car.) y dirección |
| Retraso antes del DM | SÍ | `dmDelaySeconds`, hasta 86400 (24h). **Es fijo, no aleatorio** — la propia API no ofrece variarlo al azar |
| Retraso antes de la respuesta pública | NO configurable por separado | Zernio nunca publica la respuesta pública antes de mandar el privado, así que hereda el mismo retraso — no hay un segundo control |
| Filtro "solo seguidores" | SÍ | `followGate` (objeto vacío = activado, con valores por omisión sensatos de Zernio) |
| Asociar a una publicación concreta | SÍ (solo en `comment`, no en `story_reply`) | Requiere **dos identificadores**: el propio de Zernio (`postId`) y el de la red (`platformPostId`) |
| Reglas solapadas (misma cuenta, mismas palabras) | Comportamiento confirmado, sin API para resolverlo | La automatización **más antigua** se queda con todos los comentarios coincidentes; la más nueva no recibe ninguno — comprobado contra una cuenta real (una automatización del 16 de agosto con 6 registros, la del día 20 con 0). Por eso existe `Support/AutomationOverlap.php` en el lado de nuestra app, no en Zernio |
| Activar/desactivar al crear | Comportamiento inesperado confirmado | Zernio **ignora `isActive: false` al crear** — nace siempre encendida. Hay que crearla y luego apagarla en un segundo `PATCH` |

---

## Consecuencia directa para el diseño de Social Commerce (sección 3-4 del prompt maestro)

El prompt maestro pide una estrategia de rotación configurable: **Aleatoria / Rotación secuencial / Menos utilizada recientemente**, con una tabla `social_message_template_usage` para sostenerlas.

Con la evidencia de arriba:

- **"Aleatoria" SÍ es oficialmente posible**, porque es literalmente lo único que Zernio hace: delega la selección de variante a Zernio de forma nativa mandando el array `dmMessageVariations`/`commentReplyVariations`.
- **"Rotación secuencial" y "menos utilizada recientemente" NO son alcanzables respetando las reglas oficiales**, porque la decisión de qué variante mandar ocurre dentro de la infraestructura de Zernio, en el instante en que llega el comentario — nuestra aplicación nunca ve ese evento antes de que la respuesta ya haya salido. No hay ningún endpoint que permita "fijar la variante activa antes de cada envío" ni un webhook de "va a responder, decide tú". Intentar forzarlo requeriría dejar de usar el motor de automatización de Zernio y construir un sistema propio de recepción de comentarios en tiempo real contra la API de Meta directamente — eso es una integración completamente distinta (con su propio App Review, Business Verification, tokens de página, gestión de rate limits de Meta), no una extensión de lo que ya existe, y contradice la regla del prompt maestro de reutilizar lo existente en vez de duplicarlo.

**Siguiendo la regla explícita del prompt maestro ("si una acción no está oficialmente disponible: NO IMPLEMENTARLA MEDIANTE MÉTODOS NO OFICIALES; mostrarlo como NO disponible mediante la API oficial bajo estas condiciones")**, el diseño de Social Commerce (Fase 2) ofrecerá:

- **Rotación aleatoria**: disponible, implementada delegando en la rotación nativa de Zernio (hasta 6 variantes generadas desde plantillas `{producto}`/`{precio}`).
- **Rotación secuencial / menos usada recientemente**: **NO disponible mediante la API oficial bajo estas condiciones**. Se documentará así en el módulo (mensaje explicativo en la UI, no un selector que finja funcionar).
- **Seguimiento de uso SÍ es posible de forma indirecta**: el webhook `message.received` se dispara también para mensajes **salientes** (`direction: outgoing`), incluidos los que la automatización de Zernio envía sola. Comparando el texto recibido contra las variantes configuradas se puede registrar, después del hecho, cuál se usó y cuándo — suficiente para alimentar `social_message_template_usage` como **panel de reporte**, aunque no como mecanismo de control de la rotación en sí.

## Requisitos de cuenta / App Review / Business Verification

**NO VERIFICADO.** Nada en el código ni en la documentación del repositorio confirma los requisitos de verificación de negocio o revisión de app que Zernio exige a sus clientes para habilitar comentarios/DMs de Instagram — esa relación (alta de cuenta en Zernio, planes, verificación) ocurre fuera de este sistema, directamente entre la empresa cliente y Zernio. No se inventa un requisito aquí.

## Límites de la API (rate limits)

**NO VERIFICADO** de forma explícita — no hay ningún comentario en el código que documente un límite numérico de peticiones por minuto/hora de Zernio. Los únicos límites confirmados son los de contenido/campos ya listados en la tabla (200 registros por página de logs, 5 variantes, 86400s de retraso máximo, 640/1000/500 caracteres). Cualquier límite de tasa deberá inferirse en producción con manejo de errores 429 (contemplado igualmente por el diseño de reintentos de Jobs de la Fase 3 en adelante) y no se asume un número.

---

Esta investigación no modifica código. Sirve de base verificada para la Fase 2 (arquitectura).
