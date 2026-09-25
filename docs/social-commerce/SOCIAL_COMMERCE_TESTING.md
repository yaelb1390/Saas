# Social Commerce — pruebas

Fecha: 2026-09-25. 52 pruebas, `tests/Feature/SocialCommerce/`, todas en verde.

## Cómo correrlas

```
docker exec bmos_app php artisan test tests/Feature/SocialCommerce/
```

**Con la ruta exacta, no `tests/Feature/` a secas** — el proyecto ya tiene un problema conocido de `artisan test` saltándose carpetas en silencio sin argumento (ver memoria del proyecto). Exigir siempre la línea final `Tests: N passed` para confirmar que corrió completa.

## Mapa de archivos

| Archivo | Qué cubre |
|---|---|
| `RuleTest.php` | Modelo `Rule`: relaciones, aislamiento por empresa a nivel de modelo, `restrictOnDelete()` del producto (con `forceDelete()`, no `delete()` — `Product` usa SoftDeletes) |
| `TemplateRendererTest.php` | Variables conocidas/desconocidas, que una variable inválida se deja tal cual |
| `KeywordMatcherTest.php` | Los 3 modos de coincidencia, mayúsculas/acentos, tolerancia a erratas, «precioso» no dispara con «precio» salvo en modo «contiene» |
| `RuleSyncServiceTest.php` | Crear/pausar/activar/borrar contra un Zernio simulado (`Http::fake()`), nunca contra el servicio real |
| `RuleControllerTest.php` | CRUD completo por HTTP: permisos, aislamiento por empresa a nivel de ruta (404), validación de producto ajeno y variables desconocidas |
| `WebhookControllerTest.php` | Seguridad (token/firma), idempotencia (aviso repetido), lo que hace con un aviso entrante/saliente, plataforma no soportada |
| `ConversationControllerTest.php` | Enlazar cliente (nuevo o existente), crear oportunidad (y que no se pueda sin cliente, ni duplicada), aislamiento por empresa |
| `SandboxControllerTest.php` | Que el modo prueba no cree ningún registro ni llame a Zernio; reglas pausadas no cuentan |
| `DashboardControllerTest.php` | Los conteos son correctos; que «Instagram → WhatsApp» nunca aparezca (esa métrica no existe, ver overview) |

## Convenciones seguidas (heredadas del proyecto, no inventadas aquí)

- `RefreshDatabase` + SQLite en memoria (forzado por `tests/TestCase.php`, nunca toca Postgres).
- Arranque de cada prueba: `CurrentCompany::forget()` → crear empresa vía `CompanyService::create(new CreateCompanyData(...))` → `CurrentCompany::set($company->id)` → crear filas sin pasar `company_id` a mano (se autocompleta).
- `Http::preventStrayRequests()` + `Http::fake([...])` **dentro de cada prueba**, nunca un fake por defecto en `beforeEach` — un fake compartido gana el primero que casa y puede enmascarar lo que la prueba dice comprobar (lección ya documentada en la memoria del proyecto).
- Permisos: `withRole(User::create([...]), 'owner')` (helper global de `tests/Pest.php`). Los roles reales del proyecto son `owner`, `admin`, `staff`, `driver` — **no existe un rol `cashier`/`cajero`**, se usó `staff` para las pruebas de "sin permiso".

## Bugs reales que estas pruebas encontraron (no solo lo que confirman)

Vale la pena leerlos porque son el tipo de fallo que no se ve revisando el código a simple vista — todos salieron de subir un nivel (de servicio a HTTP) o de correr contra Postgres real:

1. **`StoreRuleRequest::post()` chocaba con `Illuminate\Http\Request::post()`.** Rompía la clase entera (`Declaration ... must be compatible`) en cuanto una petición HTTP real la instanciaba — invisible en pruebas que solo llaman al FormRequest indirectamente. Renombrado a `publicacionElegida()`.
2. **`RuleSyncService::sync()` comparaba `$rule->status === RuleStatus::Draft`**, pero un `Rule::create()` recién hecho por HTTP (sin pasar `status`, que está fuera de `$fillable` a propósito) tiene ese atributo en `null` en memoria, no en `Draft` — Eloquent no relee los valores por omisión de la columna solo. La condición nunca entraba, la regla se creaba activa en Zernio pero quedaba `draft` en nuestra base. La prueba a nivel de servicio no lo vio porque usaba una factory que sí fija `status` a mano.
3. **Los permisos nuevos no llegaban a una empresa creada después del despliegue.** La migración de datos solo repara empresas que ya existían; `RoleProvisioner::PERMISSIONS`/`ROLES` es la lista que de verdad usa una empresa nueva, y nunca se había tocado. Sin esto, el dueño de una empresa recién registrada recibía 403 en toda la pantalla.
4. **`ConversationController::createOpportunity()` no exigía cliente enlazado** antes de crear la oportunidad, aunque la vista ya insinuaba que hacía falta. `CrmService::openOpportunity()` acepta `customer: null` sin quejarse, así que nada lo impedía a nivel de código.

## Lo que NO está cubierto (honesto, no una lista de excusas)

- El webhook end-to-end no se ha probado contra el servicio real de Zernio (solo simulado) — no hay entorno de pruebas de Zernio disponible para este proyecto.
- No hay pruebas de carga/concurrencia real sobre la idempotencia del webhook (la lógica de "inserta primero, confía en la restricción" está copiada de un patrón ya probado en producción para Polar, pero no se repitió el mismo ejercicio de concurrencia aquí).
- El dashboard no tiene pruebas de la agrupación por día en el borde de cambio de zona horaria.
