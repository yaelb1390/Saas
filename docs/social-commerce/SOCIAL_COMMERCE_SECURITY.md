# Social Commerce — seguridad

Fecha: 2026-09-25. Checklist de lo que ya está cubierto, con evidencia; lo que falta se dice como falta, no se da por hecho.

## Aislamiento por empresa (multi-tenant)

Las 9 tablas del módulo usan `App\Modules\Core\Tenancy\BelongsToCompany` + `HasCompany` (excepto `social_commerce_webhook_events`, que es de plataforma — ver más abajo). El filtro por `company_id` lo aplica el `CompanyScope` global de forma automática; ningún controlador de este módulo hace una comprobación manual de "¿esto es de mi empresa?" aparte de eso — es exactamente el mismo mecanismo que usan las otras 70+ tablas del proyecto, no uno propio.

**Verificado con pruebas HTTP reales** (no solo a nivel de modelo): `RuleControllerTest` y `ConversationControllerTest` confirman que una empresa recibe 404 (no 403 — no delata que el recurso existe) al intentar editar/borrar/enlazar un recurso de otra empresa.

Un punto que sí exigió cuidado explícito: `StoreRuleRequest` valida que `product_id` pertenezca a la empresa activa usando `Product::find($id)` (pasa por el global scope), **no** `Rule::exists('products', 'id')` (consulta cruda que se saltaría el aislamiento). Está comentado en el propio código para que nadie lo cambie por descuido.

## Credenciales

- No hay ninguna credencial propia de Social Commerce. Se reutiliza `companies.social_api_key` (cast `encrypted`), la misma que ya usa el módulo `Social`.
- `social_commerce_settings.webhook_secret`: cast `encrypted`, y **fuera de `$fillable`** — no puede llegar de un formulario ni por un `create()`/`update()` con datos de más. Se genera una sola vez (`Settings::paraEmpresa()`, `Str::random(64)`) y nunca se regenera al guardar, porque eso dejaría el webhook ya registrado en Zernio firmando con un secreto que ya no reconoce.

## Webhook propio

- **Tenant**: resuelto por un token opaco en la URL, nunca por nada del cuerpo.
- **Firma**: HMAC-SHA256 del cuerpo crudo, comparación en tiempo constante (`hash_equals`).
- **Mismo 401 genérico** tanto para token inexistente como para firma inválida — no delata cuál de los dos falló.
- **Idempotencia**: inserta-primero-y-confía-en-la-restricción-única, no un `exists()` previo (evita la condición de carrera bajo entregas simultáneas).
- **Gap conocido y documentado, no ignorado**: a diferencia del webhook de Polar, este no tiene ventana de frescura (sello de tiempo) porque Zernio no manda una cabecera de tiempo — no hay nada que comprobar sin inventar un dato que la API no ofrece. La idempotencia por `event_id` es la única defensa contra reintentos/repeticiones, y no cubre una repetición maliciosa deliberada del mismo aviso capturado (sí cubre los reintentos legítimos de Zernio, que es lo que existe en la práctica).
- **Sin IP allowlisting**, igual que los otros dos webhooks del proyecto (Evolution, Polar) — no es una omisión de este módulo, es la postura ya existente en el proyecto.

## Permisos

Tres permisos, mismo criterio que `social.*`:

| Permiso | Para qué | Quién lo tiene por defecto |
|---|---|---|
| `social_commerce.view` | Ver reglas, conversaciones, dashboard | `owner`, `admin` |
| `social_commerce.manage` | Crear/editar/borrar reglas, enlazar clientes, abrir oportunidades, usar el sandbox | `owner`, `admin` |
| `social_commerce.connect` | Pegar la clave de Zernio, conectar la cuenta | `owner`, `admin` |

**No hay Policies de Laravel** — el proyecto entero no las usa (confirmado en la auditoría, §13). La autorización es `can:permiso` a nivel de ruta, y el aislamiento de tenant es un mecanismo aparte (el global scope, no una comprobación de permiso).

Registrados en **dos** sitios, no uno: la migración `2026_09_25_100009_grant_social_commerce_to_existing_roles.php` (repara empresas que ya existían) **y** `RoleProvisioner::PERMISSIONS`/`ROLES` (para que una empresa creada después de hoy los reciba). Olvidar el segundo es un fallo silencioso que solo se ve con una prueba HTTP real contra un usuario recién creado — pasó en esta misma entrega, ver `SOCIAL_COMMERCE_TESTING.md`.

## Fuga de información

`WhatsApp\Support\ProductLookup` ya establece el precedente de nunca exponer `cost` (margen) a nada que un cliente pueda ver; `TemplateRenderer` sigue el mismo criterio — solo lee `name`, `price`, `sku`, `category.name` del producto, nunca `cost`.

## Auditoría (`owen-it/laravel-auditing`)

`Settings` usa `Auditable` (guarda configuración/credenciales de la empresa — mismo criterio que `Company`/`AiSetting`/`SocialWelcomeSetting`). `Rule` y el resto de modelos de negocio de alto volumen **no** la usan, mismo criterio que el resto del proyecto (auditoría, §14): no se audita todo por defecto.

## Rate limiting

`syncPosts` (llama a Zernio para traer publicaciones) lleva `throttle:10,1`, mismo criterio que el equivalente en `Social`. El resto de rutas del panel no llevan `throttle` propio, consistente con la convención del proyecto (inline por ruta, solo donde hay una llamada externa cara o una acción masiva/destructiva).
