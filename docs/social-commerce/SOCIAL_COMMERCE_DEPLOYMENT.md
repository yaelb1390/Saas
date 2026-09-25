# Social Commerce — puesta en producción

Fecha: 2026-09-25. Lo que hace falta para que este módulo funcione de verdad en producción, aparte de que el código ya esté desplegado.

## 1. Migraciones (manual, como todo lo demás en este proyecto)

Producción corre en Vercel contra Supabase (schema `bmos`), y **Vercel no corre migraciones** (memoria del proyecto). Aplicar a mano, en orden, las 10 migraciones nuevas:

```
2026_09_25_100000_create_social_commerce_settings_table.php
2026_09_25_100001_create_social_commerce_rules_table.php
2026_09_25_100002_create_social_commerce_rule_templates_table.php
2026_09_25_100003_create_social_commerce_rule_template_usage_table.php
2026_09_25_100004_create_social_commerce_contact_identities_table.php
2026_09_25_100005_create_social_commerce_conversations_table.php
2026_09_25_100006_create_social_commerce_messages_table.php
2026_09_25_100007_create_social_commerce_opportunity_links_table.php
2026_09_25_100008_create_social_commerce_webhook_events_table.php
2026_09_25_100009_grant_social_commerce_to_existing_roles.php
```

La última (`..._100009_...`) es la que reparte los permisos `social_commerce.*` a los roles `owner`/`admin` de las empresas que **ya existen** en producción. Sin ella, ningún dueño actual ve el módulo aunque se le active — tendría el permiso ausente, no solo el módulo apagado.

Las 9 migraciones de tabla ya se probaron contra Postgres real (no solo SQLite) antes de esta entrega — ver `SOCIAL_COMMERCE_TESTING.md` para el porqué de esa comprobación extra (hay precedente en el proyecto de un `text` vs `string` que SQLite no cazaba y PostgreSQL sí).

## 2. Nada nuevo en `.env`

Social Commerce no introduce ninguna variable de entorno propia. Reutiliza:
- `companies.social_api_key` (ya existe, es la clave de Zernio del módulo `Social`).
- El dominio `api.zernio.com` (ya tiene que estar permitido en el firewall de salida si `Social` ya funciona).

Si el firewall de producción restringe salida HTTP y `Social` **no** está en uso todavía en esa instancia, confirmar que `api.zernio.com` esté permitido de todas formas — Social Commerce lo necesita igual.

## 3. Activar el módulo para una empresa

`social_commerce` es una clave nueva en `ModuleRegistry`, vendida por separado (decisión de negocio, no técnica). Una empresa no ve nada de este módulo hasta que:

- Tenga un `Plan` que incluya `'social_commerce'` en su columna `modules`, **o**
- Se le dé un override manual en `companies.modules`.

**Pendiente, fuera del alcance de esta entrega (trabajo de negocio, no de código)**: crear el plan/producto correspondiente en Polar y enlazarlo (`polar_product_id`) si se va a vender con cobro automático, tal como ya existe para los demás planes. Sin esto, el módulo se puede activar manualmente por empresa, pero no se puede vender por autoservicio.

## 4. Verificación post-despliegue

1. `php artisan route:list | grep social-commerce` — confirmar que las rutas del panel y el webhook aparecen.
2. Activar el módulo para una empresa de prueba, entrar y conectar Zernio (ver `SOCIAL_COMMERCE_META_SETUP.md`).
3. Crear una regla, usar el **Modo prueba** antes de dar por buena la configuración (no llama a Zernio, no puede romper nada).
4. Encender "Recibir avisos de Instagram" en Ajustes y confirmar en el registro de la empresa en Zernio que el webhook quedó dado de alta (o, más simple: comentar de verdad y comprobar que aparece en **Conversaciones**).
5. Revisar `social_commerce_webhook_events` en la base — si hay filas con `result = unresolved`, leer `note` para el motivo.

## 5. Qué NO migrar/mover

- **No** tocar `social_welcome_settings` ni el webhook de `Social` (`/webhooks/redes/{token}`) — son de otro módulo, y esta entrega los dejó exactamente como estaban (confirmado con `git status` en cada fase, no solo de palabra).
- **No** hace falta backfill de datos históricos: las tablas nuevas empiezan vacías y se rellenan solas según van llegando conversaciones nuevas después de activar el webhook.

## 6. Rollback

Si algo sale mal, apagar el módulo (quitarlo de `companies.modules` o del plan) detiene el acceso al panel inmediatamente. El webhook de Zernio, si ya estaba registrado, sigue entregando avisos hasta que se apague explícitamente desde Ajustes (o se borre a mano desde el panel de Zernio) — apagar el módulo del lado de este sistema no lo da de baja del lado de Zernio.

Las migraciones son reversibles (`down()` con `dropIfExists` en las 9 de tabla; la de permisos revierte el `role_has_permissions` insertado), pero borrar las tablas borra también cualquier conversación/oportunidad ya capturada — pensarlo dos veces antes de revertir en un entorno con datos reales.
