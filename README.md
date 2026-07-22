# BM Business OS

Business Operating System modular, multiempresa y listo para producción, construido sobre
**Laravel 12 / PHP 8.4 / PostgreSQL**. Ver [MASTER_PLAN.md](MASTER_PLAN.md) para la arquitectura
completa y [CLAUDE.md](CLAUDE.md) para las reglas del proyecto.

## Stack

- **Backend:** Laravel 12, PHP 8.4, PostgreSQL (Supabase gestionado en prod), Redis + colas.
- **Frontend:** Blade + Livewire 3 + Tailwind CSS v4 + Alpine.js (vía Vite).
- **Auth:** Laravel Fortify (login + 2FA) + Sanctum (tokens API).
- **Roles/permisos:** spatie/laravel-permission con *teams* por `company_id`.
- **Auditoría:** owen-it/laravel-auditing.
- **Calidad:** Pest (tests), Larastan/PHPStan (análisis estático), Pint (PSR-12).
- **Infra dev:** Docker Compose (php-fpm, nginx, postgres, redis, n8n).

## Arquitectura modular

El código de negocio vive en `app/Modules/{Modulo}` con submódulos
`Models / Services / Repositories / DTOs / Http / Events / Listeners / Policies / Providers`.
Regla de oro: **cero lógica de negocio en los Controllers**; los servicios orquestan, los
repositorios abstraen la persistencia.

### Multiempresa (tenancy)

Toda entidad de negocio se aísla por `company_id`:

- `App\Modules\Core\Tenancy\CurrentCompany` — contexto de la empresa activa (singleton).
- `App\Modules\Core\Tenancy\BelongsToCompany` — trait que registra el Global Scope y asigna
  `company_id` automáticamente al crear.
- `App\Modules\Core\Tenancy\CompanyScope` — Global Scope que filtra por la empresa activa.
- `App\Modules\Core\Http\Middleware\SetCurrentCompany` — fija el tenant y el *team* de spatie
  desde el usuario autenticado.

## Puesta en marcha (Docker)

```bash
# 1. Levantar servicios (php-fpm, nginx, postgres, redis)
docker compose up -d --build app web postgres redis

# 2. Migrar y sembrar datos demo
docker compose exec app php artisan migrate:fresh --seed

# 3. Compilar assets (Node en el host)
npm install && npm run build
```

App disponible en **http://localhost:8000**.

> Los puertos 8080/5678 pueden estar ocupados por otros servicios del host (Evolution API,
> n8n existente). Este proyecto usa **8000** para la web y **5679** para su n8n opcional
> (`docker compose --profile n8n up -d n8n`).

### Usuarios sembrados

| Rol | Email | Password |
|-----|-------|----------|
| Super Admin (plataforma) | `superadmin@bmbusiness.os` | `password` |
| Owner (empresa demo) | `owner@bmbusiness.os` | `password` |

## Comandos útiles

```bash
docker compose exec app php artisan test        # Pest (12 tests)
docker compose exec app ./vendor/bin/phpstan analyse   # Larastan nivel 5
docker compose exec app ./vendor/bin/pint       # Formato PSR-12
```

## Estado

- **Fase 0 — Bootstrap:** ✅ Docker, Laravel 12, Postgres/Redis, Pest, Larastan, Pint.
- **Fase 1 — Fundación (core):** ✅ Multiempresa (`companies`/`branches`/`warehouses`),
  aislamiento por `company_id`, auth + 2FA, roles/permisos por empresa, auditoría, dashboard.
- **Fase 2 — Inventario + Compras:** ✅ Catálogo (`categories`/`products`), existencias por
  almacén (`stock`) con kardex (`stock_movements`) vía `StockService` (bcmath + bloqueo de
  fila), proveedores y órdenes de compra cuya **recepción incrementa el stock**
  (`PurchaseOrderService`).
- **Fase 3 — Ventas + POS + Caja:** ✅ Sesiones de caja con arqueo (`CashService`), ventas que
  **descuentan stock** (`SaleService`) y un `CheckoutService` de POS que orquesta venta + cobro
  en caja en una sola transacción (rollback total si falta stock).
- **Fase 4 — Facturación (DGII):** ✅ Secuencias fiscales con **asignación atómica de NCF**
  (`FiscalSequenceService`, bloqueo de fila) y `InvoiceService` que emite la factura desde una
  venta (NCF único, snapshot de líneas, valida agotamiento/vencimiento y evita duplicar).
- **Fase 5 — CRM + WhatsApp/Evolution:** ✅ Clientes, pipeline con etapas (provisionado por
  empresa al crearla) y oportunidades (`CrmService`); WhatsApp con **gateway abstracto**
  (Evolution API real / log en dev), envío y recepción de mensajes, y **webhook entrante
  firmado** que resuelve el tenant por la instancia. Enlaza conversaciones con el cliente por
  teléfono.
- **Fase 6 — IA + RAG:** ✅ **Capa de proveedor intercambiable** (`AiProvider`: OpenAI, Claude o
  Local determinista); RAG (`RagService`: ingesta → chunks → embeddings → recuperación por
  similitud coseno → respuesta con contexto); y **clasificador de sentimientos** que se dispara
  automáticamente sobre los mensajes entrantes de WhatsApp (listener del evento de dominio). 49
  tests verdes, Larastan y Pint limpios.

> **Embeddings:** se guardan como JSON y la similitud se calcula en PHP para funcionar sobre
> PostgreSQL estándar. Para escalar, migrar la columna a `vector` de pgvector y sustituir
> `RagService::retrieve()` por una consulta ANN (el resto del pipeline no cambia).

- **Fase 7 — Delivery · Finanzas · RRHH · Reportes + Portal:** ✅ Entregas con estados
  (`DeliveryService`), finanzas con balance por cuenta y **automatización venta→ingreso**
  (`SaleCompleted` → asiento en caja), RRHH (empleados + asistencia), **resumen ejecutivo**
  (`ReportService`) integrado en el dashboard, y **portal del empleado** (`/portal/perfil`). 60
  tests verdes, Larastan y Pint limpios.

> **Portal del cliente:** el del empleado está implementado (usuario ↔ empleado). El portal del
> cliente con guard/autenticación propia queda como siguiente incremento (los clientes del CRM
> aún no son usuarios autenticables).

### Proyecto completo (Fases 0–7)

10 módulos de negocio sobre el Core multiempresa. Cadena de valor demostrada de punta a punta en
el seeder: **compra → stock → venta POS → caja → factura NCF → cliente CRM → WhatsApp → IA
(RAG + sentimiento) → entrega → ingreso en finanzas**, con automatizaciones por eventos de dominio
en cada eslabón.

### Límites entre módulos (Fase 3)

`Cash` es autónomo · `Sales` depende de `Inventory` (descuento de stock) · `POS` depende de
`Sales` y `Cash` (orquesta el checkout). Ningún módulo accede a las tablas de otro: la
comunicación es siempre servicio-a-servicio.
