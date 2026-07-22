# Despliegue y rendimiento

## ⚠️ El comando que SIEMPRE hay que ejecutar tras un cambio

```bash
docker exec bmos_app php artisan optimize
```

Esto precompila **configuración, rutas y vistas**, lo que ahorra trabajo en cada petición.

> **La trampa que debes conocer:** con la configuración precompilada, **cambiar el `.env` NO tiene
> efecto hasta volver a ejecutar ese comando**. Si editas el `.env` (credenciales de R2, correo,
> claves, etc.) y «no pasa nada», casi siempre es esto.

Para revertir a modo sin precompilar (útil si algo se comporta raro):

```bash
docker exec bmos_app php artisan optimize:clear
```

### Cuándo ejecutar `optimize`

- Después de cambiar el **`.env`**.
- Después de desplegar **código nuevo** (rutas, vistas o configuración).
- Después de reconstruir los contenedores.

### ⚠️ Para correr los tests: usa `composer test`

Con la configuración precompilada, los tests intentarían correr contra la base de datos **real** en
vez de SQLite en memoria. El proyecto ya lo detecta y aborta con un mensaje claro. Ejecuta siempre:

```bash
docker exec bmos_app composer test      # limpia la config y luego corre los tests
```

No uses `php artisan test` a secas mientras la configuración esté precompilada.

## Rendimiento: cómo está optimizado el panel

### El problema que se corrigió

`Company::hasModule()` se llama una vez por cada elemento del menú y por cada tarjeta del panel.
Antes, cada llamada **volvía a consultar la base de datos** porque el resultado no se guardaba. Medido:

| Página     | Antes | Después (1.ª carga) | Después (en régimen) |
|------------|-------|---------------------|----------------------|
| Dashboard  | 79    | 20                  | **5**                |
| POS        | 52    | 16                  | —                    |
| Inventario | 51    | 15                  | —                    |

### Qué se hizo

1. **`Company::loadedSubscription()`** carga la suscripción y su plan **una sola vez por instancia**
   (antes repetía la consulta en cada llamada). Fue el mayor ahorro: 79 → 29.
2. **`CurrentCompany::model()`** resuelve la empresa activa **una vez por petición** y todos la
   comparten (layout, dashboard, middlewares y controladores). Antes cada uno hacía su `Company::find()`.
3. **Resumen ejecutivo y campana de alertas** se cachean **60 segundos por empresa**
   (`ReportService`, `AlertService`). Son 11 consultas de agregación que se repetían en cada carga.

### Lo que NO se cachea (a propósito)

**El control de acceso** (si la empresa tiene un módulo, si la suscripción está vigente) **no se
cachea entre peticiones**. Se resuelve con una sola consulta por petición y se evalúa siempre con la
fecha actual. Cachearlo ahorraría unas pocas consultas, pero abriría la puerta a que una empresa
suspendida o vencida siguiera entrando hasta que la caché expirase. No compensa.

### Red de seguridad

`tests/Feature/Performance/QueryBudgetTest.php` **cuenta las consultas reales** de cada página y
falla si se disparan. Si alguien reintroduce el patrón N+1, lo detecta antes de producción.
`CacheIsolationTest.php` verifica que la caché **nunca mezcle datos entre empresas**.

## Comandos útiles

```bash
# Ver/limpiar cachés de aplicación
docker exec bmos_app php artisan cache:clear      # datos cacheados (KPIs, alertas)
docker exec bmos_app php artisan optimize:clear   # config, rutas y vistas precompiladas

# Comprobar el presupuesto de consultas
docker exec bmos_app php artisan test tests/Feature/Performance
```

> Nota: al recrear el contenedor `bmos_app`, nginx puede quedarse con su IP anterior. La
> configuración ya usa el DNS interno de Docker para re-resolverla, así que no debería hacer falta
> reiniciar `bmos_web`.
