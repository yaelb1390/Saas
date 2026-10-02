# Facturación Electrónica DGII (e-CF)

Documentación técnica del módulo `app/Modules/ElectronicInvoicing` (clave de módulo `e_invoicing`).
Plan completo y decisiones: ver el plan aprobado el 2026-10-02 (8 fases, 0–7).

> **BMIA no está certificado ni autorizado por la DGII.** Este módulo prepara, valida y (en fases
> posteriores) firma y envía e-CF. La autorización de cada empresa la da la DGII tras su proceso de
> certificación. La pantalla lo dice de forma permanente.

## Regla fundamental: nada inventado

Toda regla fiscal sale de un documento oficial de la DGII y se guarda como **configuración versionada**
(`config/ecf.php`) con su referencia. Lo que la documentación no aclara va a
`ecf.pending_verification` y el paso que lo necesita no se implementa hasta confirmarlo.

Documentos de referencia (página «Documentación sobre e-CF», consultada 2026-10-02); siglas usadas en
el código:

| Sigla | Documento | Fecha DGII |
|---|---|---|
| [DT] | Descripción Técnica Servicios DGII | 29/05/2026 |
| [IT] | Informe Técnico e-CF v1.0 | 06/04/2026 |
| [FIR] | Firmado de e-CF | 22/11/2023 |
| [FMT] | Formato Comprobante Fiscal Electrónico (e-CF) v1.0 | 30/10/2025 |

## Esquemas oficiales (XSD)

- Carpeta: `resources/dgii/ecf/v1.0/` (15 archivos: e-CF 31–47, RFCE 32, ARECF, ANECF, ACECF, Semilla).
- **Sin modificar**: algunos traen BOM UTF-8; se dejan tal cual.
- `manifest.json` guarda por archivo: elemento raíz, título y fecha de la DGII, URL de origen, bytes y
  `sha256`. `SchemaRegistry::integrity()` compara la huella; el resumen del módulo avisa si alguno falta
  o fue retocado.
- Son autocontenidos (sin `import`/`include`): se validan con `LIBXML_NONET`, sin red.
- **La DGII cambia contenido sin subir la versión 1.0** [FMT p.1]. Por eso cada documento guardará la
  fecha del esquema con el que se validó (`SchemaRegistry::schemaDate()`), no solo «1.0».
- Para actualizar: descargar de la página oficial (la DGII responde 403 a clientes sin cabeceras de
  navegador: usar `User-Agent` y `Referer` de navegador), reemplazar el archivo, regenerar su entrada del
  manifiesto y dejar constancia en este documento.

### Hallazgo: el XSD solo valida el documento firmado

La raíz `ECF` termina en `FechaHoraFirma` (obligatorio) seguido de `<xs:any minOccurs="1">` — el lugar de
la firma. Un XML sin firmar **no valida** contra el XSD oficial. El validador previo a la firma (fase 2)
valida una **copia** con un elemento marcador en ese lugar (permitido por `processContents="skip"`), sin
tocar el XSD.

## Fase 0 — Cimientos (2026-10-02)

- `config/ecf.php`: ambientes (`testecf`/`certecf`/`ecf`), hosts, tipos y su XSD, formato del e-NCF,
  umbral RFCE de la factura 32 (RD$250.000), retención (10 años), plazos de contingencia, pendientes.
- Dominio: `EcfType` (31–47), `Environment` (pruebas/certificación/producción), `SetupStatus`
  (No configurado … Error). Etiquetas y esquemas desde configuración, no en el código.
- `SchemaRegistry` (esquemas + integridad) y `RuntimeRequirements` (extensiones `openssl`, `dom`,
  `libxml`, `bcmath`): el resumen pregunta al **propio servidor** qué tiene, así se confirma producción
  (vercel-php) sin suponer.
- Tabla `electronic_invoicing_settings` (una fila por empresa, `company_id` único): datos fiscales del
  emisor, ambiente, estado, proveedor y su configuración **cifrada** (`encrypted:array`, excluida de la
  auditoría). Nace en pruebas, sin configurar, con el proveedor `fake` (no envía nada) y precarga los
  datos de «Mi empresa» (el RNC solo si tiene 9 u 11 dígitos).
- Módulo `e_invoicing` concedido a planes/empresas que ya tienen `billing`; permisos `ecf.view`,
  `ecf.configure`, `ecf.issue`, `ecf.sign`, `ecf.send`, `ecf.cancel`, `ecf.query`, `ecf.download`,
  `ecf.audit` para owner y admin (el cajero no).
- Pantalla `Administración → Facturación Electrónica` (`/panel/facturacion-electronica`): aviso fijo de
  «no es autorización», banda de ambiente sin validez fiscal, estado, requisitos técnicos, tipos y
  pendientes. Si la migración no está aplicada, abre y lo dice (sin 500).
- `TenantDataPurger::KEPT` += `electronic_invoicing_settings`.

### Migraciones de la fase (las aplica el operador en producción)

```
2026_10_02_100000_create_electronic_invoicing_settings_table
2026_10_02_100100_grant_e_invoicing_module
2026_10_02_100200_grant_e_invoicing_to_existing_roles
```

Idempotentes y solo aditivas. El código es seguro sin ellas (la pantalla avisa).

### Corrección incluida: barra superior en el teléfono

El icono de instalar la app (2026-10-01) empujaba el avatar 26 px fuera de la pantalla a 390 px. Ahora el
bloque de la empresa se encoge y recorta su nombre con «…», el de iconos no se encoge y la barra usa menos
relleno bajo 640 px. Verificado sin desbordamiento a 1280, 390, 360 y 320 px.
