# Social Commerce — automatizaciones (reglas)

Fecha: 2026-09-25. Cómo funciona una regla de verdad, de principio a fin.

## Quién decide qué

**Zernio ejecuta la automatización. Esta aplicación solo la configura.** El matching del comentario, la elección de qué variante de texto sale, el envío del DM y de la respuesta pública ocurren enteramente dentro de la infraestructura de Zernio — nuestra aplicación nunca ve ese momento. Ver `SOCIAL_COMMERCE_META_RESEARCH.md` para la evidencia (leída directamente del código de `ZernioClient`, no del manual de Zernio).

Esto tiene una consecuencia de diseño directa: **el estado `Rule::status` no es lo mismo que `isActive` en Zernio.** `status` es lo que el comerciante ve en el panel (incluye `draft` — recién creada, sin sincronizar — y `error` — la última sincronización falló), mientras que `isActive` es literalmente el interruptor que Zernio va a mirar en el próximo comentario.

## Ciclo de vida de una regla

```
draft ──(RuleSyncService::sync())──> active
  │                                     │
  │                                     ├──(pause())──> paused ──(activate())──> active
  │                                     │
  └──(sync() falla)──> error ──(se reintenta al guardar)──> active | error
```

- **Crear** (`RuleController::store`): guarda la regla + plantillas en una transacción, y **fuera** de la transacción llama a `RuleSyncService::sync()`. Si Zernio rechaza la automatización, la regla y sus plantillas **sí quedan guardadas** (para no perder lo escrito), pero con `status = error` y `sync_error` con el motivo — el usuario va a la pantalla de edición a corregir y reintentar.
- **Editar** (`update`): reemplaza las plantillas (borra las viejas, crea las nuevas) y vuelve a sincronizar completo.
- **Pausar/Encender** (`toggle`): llama solo a `updateAutomation($id, ['isActive' => bool])` — un PATCH parcial, no reconstruye todo el payload.
- **Borrar** (`destroy`): borra primero en Zernio (si estaba sincronizada; si Zernio no responde, se ignora el fallo — ver comentario en `RuleSyncService::delete()`), luego borra localmente (soft delete).

## El payload hacia Zernio

`Support\ZernioAutomationPayload::build(Rule $rule)` arma exactamente lo que `App\Modules\Social\Http\Requests\StoreAutomationRequest::paraZernio()` ya arma para el módulo `Social` — es la misma API por debajo. Los textos salen de `TemplateRenderer::render()` sobre el producto de la regla, no de texto ya escrito.

Un detalle no obvio, encontrado por una prueba (no a simple vista): **el estado se decide ANTES de construir el payload**, no después. Si `Rule::status` siguiera en `draft` (o, peor, en `null` — que es lo que de verdad tiene un `Rule::create()` recién hecho en memoria hasta que algo lo recarga) al momento de construir el payload, se mandaría `isActive: false`. `ZernioClient::createAutomation()` respeta esa intención con un PATCH adicional que apaga la automatización recién creada (Zernio la crea encendida siempre, pase lo que pase, y el cliente lo corrige a mano si se pidió apagada). Por eso `RuleSyncService::sync()` fija `status = Active` como primera línea, antes de tocar el payload — y comprueba `null` además de `Draft`, exactamente por el motivo de arriba.

## Solapamiento entre reglas

Si dos reglas activas de la misma cuenta comparten palabra clave y ámbito, **la más antigua se queda con todos los comentarios; la más nueva no recibe ninguno** — comportamiento real de Zernio, no un bug de esta aplicación (confirmado contra una cuenta real, documentado en `App\Modules\Social\Support\AutomationOverlap`). El modo prueba (sandbox) reproduce este orden: itera las reglas activas por `id` ascendente y se queda con la primera que coincide.

## Modo prueba (sandbox)

`SandboxController` + `KeywordMatcher` simulan localmente qué pasaría con un comentario, **sin publicar nada, sin llamar a Zernio, sin crear ningún registro**. El algoritmo de coincidencia es una aproximación (normaliza acentos/mayúsculas, respeta los 3 modos, tolerancia a erratas con Levenshtein) — el algoritmo exacto de Zernio no es público, así que el resultado puede no coincidir al milímetro con la vida real. Ver `SOCIAL_COMMERCE_TESTING.md` para los casos cubiertos.
