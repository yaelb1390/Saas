# Social Commerce — plantillas y variables

Fecha: 2026-09-25.

## Variables disponibles

Lista fija en `TemplateRenderer::VARIABLES` — una variable que no está aquí se rechaza al guardar (`StoreRuleRequest::withValidator()`), no se inventa ni se borra en silencio.

| Variable | De dónde sale | Nota |
|---|---|---|
| `{producto}` | `product.name` | |
| `{precio}` | `product.price` + `company.currency` | formateado con `number_format(...,2)` |
| `{moneda}` | `company.currency` | |
| `{sku}` | `product.sku` | |
| `{categoria}` | `product.category.name` | cadena vacía si el producto no tiene categoría |
| `{url_producto}` | — | **siempre vacía**: este sistema no tiene página pública de producto. No se inventa una URL que no existe. |
| `{url_whatsapp}` | `WhatsAppLinkBuilder` | `wa.me/<numero>?text=...`, vacío si no hay número configurado en Ajustes |
| `{nombre_cliente}` | — | vacío en la sincronización con Zernio (no hay cliente identificado todavía en ese momento); se rellena solo cuando se usa en otro contexto que sí conozca al cliente |

Una variable desconocida en el texto **se deja tal cual** al renderizar (`Hola {inventado}` → `Hola {inventado}`), nunca se borra ni se reemplaza por algo adivinado — pero nunca debería llegar a renderizarse porque el formulario ya la rechazó antes.

## Dónde vive el texto sin resolver

`social_commerce_rule_templates.body` guarda el texto **con las variables sin resolver**. El renderizado ocurre en el momento de sincronizar con Zernio (`ZernioAutomationPayload::build()`), no se persiste ya relleno — así, si el precio del producto cambia en Inventario, la próxima vez que se guarde/sincronice la regla el texto sale actualizado sin que nadie tenga que tocar la plantilla a mano.

## Rotación: qué SÍ y qué NO

Hasta 6 textos por regla y canal (1 principal + 5 alternativas — tope real de Zernio, validado en `StoreRuleRequest`).

- **SÍ disponible**: rotación **aleatoria**, delegada por completo en Zernio (`dmMessageVariations`/`commentReplyVariations`). Zernio elige una al azar, por separado para el privado y para el comentario público, en el momento de responder.
- **NO disponible**: rotación secuencial, ni "la menos usada recientemente". No es una limitación de esta pantalla: no existe ningún parámetro en la API de Zernio para pedirlo, porque la selección ocurre enteramente dentro de su infraestructura, en un instante que nuestra aplicación nunca observa antes de que la respuesta ya haya salido. Ver `RotationStrategy` (un solo caso, `Random`, con el porqué documentado en el propio enum) y `SOCIAL_COMMERCE_META_RESEARCH.md`.

## Seguimiento de uso: reporte, no control

`social_commerce_rule_template_usage` registra qué plantilla se usó y cuándo, pero **después del hecho**: `TemplateUsageRecorder` compara el texto de un mensaje **saliente** (recibido por el webhook) contra las plantillas renderizadas de las reglas activas, y si coincide exactamente con alguna, anota el uso. Esto:

- **Sí sirve** para un panel de reportes ("¿qué variante se está usando más?").
- **No sirve** ni se usa para decidir qué variante enviar — eso ya lo decidió Zernio antes de que este aviso llegara.
- **Solo es fiable para el mensaje privado.** El webhook `message.received` es del buzón de conversaciones (DMs); una respuesta pública bajo un comentario no necesariamente pasa por el mismo tipo de aviso, así que la comprobación contra plantillas públicas es de mejor esfuerzo, sin garantía de la API.

## Validación al guardar

`StoreRuleRequest::withValidator()` rechaza:
- Variables desconocidas en cualquier plantilla (mensaje de error lista las inválidas y las válidas).
- Un producto que no pertenezca a la empresa activa (comprobado vía `Product::find()`, que pasa por el global scope de tenant — no un `Rule::exists()` crudo, que se saltaría el aislamiento).
- "Responder también por privado" en una automatización de historia (Zernio ya lo hace siempre ahí, activarlo explícitamente lo rechaza con un 400 — se corta antes de llamar a Zernio, mismo motivo documentado en `AutomationTrigger::admiteRespuestaEnPrivados()`).
