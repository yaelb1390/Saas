# Respaldos (copias de seguridad)

Sistema de respaldo automático de la base de datos con **spatie/laravel-backup**.

## Qué hace

- **Cada día a la 1:30 AM** genera un respaldo de la base de datos PostgreSQL (+ los archivos subidos
  en `storage/app/public`), comprimido en un `.zip`.
- **A la 1:00 AM** limpia los respaldos viejos según la retención configurada.
- Lo ejecuta el contenedor **`bmos_scheduler`** (`php artisan schedule:work`), sin cron del sistema.
- Si un respaldo falla, se registra un aviso (hoy en `storage/logs`; por correo al configurar SMTP).

## Dónde quedan los respaldos

- **Local** (por defecto): dentro del servidor, en el disco `local`
  (`storage/app/private/BM Business OS/…`). Útil, pero **no protege ante un fallo del disco**.
- **Externo — Cloudflare R2** (recomendado, off-site): cuando se configura, sube una copia fuera del
  servidor. **Esto es lo que realmente te salva** si la máquina se daña.

## Activar el respaldo externo en Cloudflare R2

1. En el panel de Cloudflare → **R2** → crea un bucket, por ejemplo `bmos-backups`.
2. **R2 → Manage API Tokens** → crea un token con permiso de **Object Read & Write** sobre ese bucket.
   Copia el *Access Key ID*, el *Secret Access Key* y la URL del **endpoint S3**
   (`https://<accountid>.r2.cloudflarestorage.com`).
3. Rellena en el `.env`:
   ```
   R2_ACCESS_KEY_ID=xxxxxxxx
   R2_SECRET_ACCESS_KEY=xxxxxxxx
   R2_BUCKET=bmos-backups
   R2_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
   R2_REGION=auto

   BACKUP_DISKS=local,r2
   ```
4. Recicla los contenedores PHP: `docker compose up -d`.
5. Comprueba: `docker exec bmos_app php artisan backup:run` → debe aparecer también en el bucket R2.

## Comandos útiles

```bash
# Ejecutar un respaldo ahora
docker exec bmos_app php artisan backup:run

# Solo la base de datos (más rápido)
docker exec bmos_app php artisan backup:run --only-db

# Listar los respaldos existentes y su tamaño
docker exec bmos_app php artisan backup:list

# Revisar la salud (avisa si el último respaldo es viejo o el destino creció demasiado)
docker exec bmos_app php artisan backup:monitor
```

## Restaurar un respaldo (ante un desastre)

1. Descarga el `.zip` del respaldo (de `storage/app/private/BM Business OS/` o del bucket R2).
2. Descomprímelo: dentro hay `db-dumps/postgresql-bmbusinessos.sql`.
3. Restaura ese volcado en una base limpia:
   ```bash
   # copia el .sql al contenedor de postgres y restáuralo
   docker cp dump.sql bmos_postgres:/tmp/dump.sql
   docker exec bmos_postgres psql -U bmos -d bmbusinessos -f /tmp/dump.sql
   ```

> **Ensayado el 19/07/2026.** Se restauró un respaldo real en una base de pruebas y quedó
> **idéntica a producción** (mismas empresas, usuarios, productos, ventas y facturas).

### Un mensaje que verás y NO es un problema

Durante la restauración aparece una línea:

```
ERROR: unrecognized configuration parameter "transaction_timeout"
```

Es **inofensivo**. El cliente `pg_dump` de la imagen es más nuevo que el servidor PostgreSQL 16, así
que escribe un ajuste que la versión 16 no conoce y lo ignora. **Los datos se restauran completos**
(verificado). No detengas la restauración por este mensaje.

## Retención

Configurable en `config/backup.php` (`cleanup.default_strategy`). Por defecto conserva los diarios
recientes y va rarificando (semanales/mensuales) para no crecer sin control.
