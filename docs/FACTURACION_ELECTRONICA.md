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

## Fase 1 — Datos fiscales y e-NCF (2026-10-03)

### Motor de impuestos por línea (`Tax/TaxEngine`)

Reglas tomadas del Formato e-CF [FMT]:

| Regla | Campo [FMT] |
|---|---|
| Indicador de facturación: 0 no facturable · 1 ITBIS 18 % · 2 ITBIS 16 % · 3 ITBIS 0 % · 4 exento | sección B, campo 4 |
| Monto del ítem = precio × cantidad − descuento + recargo | campo 39 |
| Precios con ITBIS incluido (indicador monto gravado = 1): gravado = suma de la tasa ÷ (1 + tasa) | campos 7, 93–95 |
| ITBIS de una tasa = monto gravado × tasa | campos 101–103 |
| Exento = suma de ítems con indicador 4 | campo 96 |
| Total = gravado + exento + ITBIS | campo 110 |

- Tasas en `config/ecf.php` (`itbis.rates`), no en código.
- `Core\Support\TaxCalculator` (serie B) **no se toca**: sigue calculando el documento entero al 18 %.
- **Hallazgo — diferencia de un céntimo.** Con precios con ITBIS incluido, la fórmula oficial no siempre
  vuelve a sumar lo cobrado. Ejemplo: cobrado RD$100,00 → gravado 84,75 → ITBIS 15,26 → total declarado
  100,01; la serie B declara ITBIS = cobrado − base = 15,25. El motor lo expone en `TaxResult::difference`.
  Un barrido de RD$0,01 a RD$500 confirma que nunca pasa de ±0,01.
- **Pendiente de verificar:** [FMT nota 11] cita «la regla de redondeos» sin definirla, y ningún documento
  revisado dice si la DGII tolera esa diferencia. Redondeo actual: mitad hacia arriba (configurable,
  `itbis.rounding`). Se confirmará con las primeras pruebas contra el ambiente de pre-certificación.
- Impuestos adicionales (selectivos, ad valorem) y retenciones: se añadirán con los tipos de e-CF que los
  usan, desde su especificación.

### Indicador de ITBIS por producto

- Columna `products.itbis_indicator` (por omisión 1 = 18 %, lo mismo que hoy: no cambia nada para nadie).
- Selector «ITBIS en la factura electrónica» en alta y edición de productos, **solo** si la empresa tiene
  el módulo `e_invoicing` (la serie B no lo lee; enseñarlo sin el módulo haría creer que «exento» cambia el
  ticket). Duplicar un producto conserva el indicador.
- Sin la columna (código antes que migración), el campo se descarta en vez de dar un 500.

### Secuencias de e-NCF (`Ncf/ElectronicNcfService`)

- **Desvío del plan, a propósito:** tabla propia `electronic_ncf_sequences` en vez de extender
  `fiscal_sequences`. El tipo de NCF de la serie B (`NcfType`) se usa en cuatro pantallas y cuatro
  validaciones; meterle los tipos E los habría mostrado en los desplegables de papel y permitido emitir un
  e-NCF por ese circuito. Riesgo cero para la serie B.
- e-NCF = `E` + tipo (2) + secuencial de 10 dígitos [IT §7]; `bigInteger` (10 dígitos no caben en 32 bits).
- El ambiente es parte de la secuencia: un número de pruebas nunca sale de producción ni al revés.
- Transacción + bloqueo de fila; se agota la secuencia más antigua antes de abrir la siguiente.
- `release()`: devuelve al uso un número que la DGII rechazó con `secuenciaUtilizada = false` [DT p.24]
  (tabla `electronic_ncf_releases`). Se reutiliza **antes** de abrir uno nuevo y **una sola vez**. Solo debe
  llamarlo quien procese esa respuesta de la DGII (fase 4).
- Alta de rangos en la pantalla (permiso `ecf.configure`): rechaza rangos que se crucen con otro del mismo
  tipo y ambiente. BMIA no pide números a la DGII: solo anota los ya autorizados en la Oficina Virtual.

### Arreglo en la serie B

`Billing\Services\FiscalSequenceService::allocate()` no ordenaba: con dos secuencias activas del mismo tipo
consumía cualquiera. Ahora toma la más antigua primero.

### Migraciones de la fase

```
2026_10_03_100000_add_itbis_indicator_to_products_table
2026_10_03_100100_create_electronic_ncf_sequences_table   (crea también electronic_ncf_releases)
```

### Pospuesto a la fase 5 (con su motivo)

- Índice único `invoices (company_id, sale_id)`: solo estorba al emitir notas de crédito/débito (33/34), que
  llegan en la fase 5; cambiarlo antes no aporta nada y toca una tabla con datos.
- Razón social / tipo de identificación del cliente: el tipo ya se deduce del documento (`TaxId`: RNC de 9
  dígitos o cédula de 11) y el nombre del cliente sirve de razón social; se revisará contra los campos del
  comprador del XSD en la fase 2 antes de añadir columnas.

## Fase 2 — Documento canónico, XML y validación (2026-10-03)

Pipeline (`Application/EcfXmlGenerator::generate()`): reglas de negocio → impuestos (`TaxEngine`) →
árbol de datos (`EcfDataMapper`) → XML (`EcfXmlBuilder`) → validación contra el XSD (`XmlValidator`).
Si algo falla no hay XML: se devuelven errores en español y nada llega a la firma.

- **Un solo constructor para los 10 tipos.** `XsdTree` lee el XSD oficial y `EcfXmlBuilder` coloca los
  datos en el orden que el esquema dicta. No hay `build31()…build47()` escritos a mano: lo que cambia entre
  tipos lo dice el esquema. Un esquema nuevo de la DGII = cambiar el archivo. Un campo que el esquema no
  tiene es un error (nunca se descarta en silencio). XML construido con DOM: un «<» en un nombre no inyecta
  etiquetas.
- **Documento canónico** (`Domain/EcfDocument`, `EcfParty`, `EcfLine`, `EcfReference`): independiente
  del XML y del proveedor. Es lo que Facturación, POS y Compras entregarán en la fase 5.
- **Mapeador único** (`EcfDataMapper`): consulta el XSD de cada tipo para saber qué campos existen; las
  condiciones (`condicional a que exista ítem gravado`…) son del Formato y están citadas en el código.
- **Validación antes de firmar** (`XmlValidator::validateUnsigned`): copia con `FechaHoraFirma` y un
  marcador en el hueco de la firma; errores de libxml traducidos («El valor «09» no está permitido en
  «TipoIngresos»»), con el texto técnico en `detail`.
- **Reglas de negocio** (`EcfDocumentValidator`), además del XSD:
  - RNC/cédula con dígito verificador (`Billing\Support\TaxId`); e-NCF del tipo correcto;
  - comprador obligatorio donde el XSD lo exige (RNC y razón social en 31, 41 y 45; razón social en 44 y 46);
  - cantidades > 0 con máximo 2 decimales (se rechazan, no se redondean a escondidas);
  - total del documento de origen vs. calculado (tolerancia de 0,01 por el redondeo documentado en la fase 1);
  - indicador por tipo [FMT notas 50–51]: 43, 44 y 47 solo exentos; 46 solo ITBIS 0 %;
  - retenciones [FMT ítem campos 5–7, totales 116–117]: indicador 1 «R» (percepción no vigente, nota 52);
    ISR retenido en el 41 solo en servicios;
  - notas 33/34 [FMT sección F]: comprobante modificado (11, 13 o 19 posiciones), código de modificación
    1–5, fecha no posterior; `IndicadorNotaCredito` = 1 si se emite pasados 30 días; la suma de notas de
    crédito no supera el total afectado [campo 110 d)].
- **Fecha del esquema** usado en cada resultado (`schemaDate`), porque la DGII cambia contenido sin subir
  la versión.

### Hallazgo: tres XSD oficiales tienen defectos

Validados con libxml (estándar de XML Schema), tres esquemas de la DGII no compilan; la DGII usa .NET, que
es más permisivo:

| Archivo | Defecto | Errata aplicada |
|---|---|---|
| `ecf-31.xsd` | Usa `IndicadorServicioTodoIncluidoType` sin definirlo | Se copia la definición **literal** del XSD oficial 32 (idéntica en 32, 33, 34 y 44) |
| `acecf.xsd` | `(?:…)` (grupo no capturante de .NET) | `(?:` → `(` |
| `rfce-32.xsd` | `(?:…)` en 4 patrones | `(?:` → `(` |

Las erratas viven en `config/ecf.php → schema_errata`, cada una con su motivo. Los archivos oficiales
**no se tocan** (su huella sigue coincidiendo); para validar se genera una copia corregida en el directorio
temporal (`SchemaRegistry::validationPath`). Si la DGII corrige un archivo, la errata deja de aplicar y el
sistema se detiene y avisa en lugar de aplicarla a ciegas.

### Cobertura

Los diez tipos (31, 32, 33, 34, 41, 43, 44, 45, 46, 47) generan XML válido contra su esquema oficial
(prueba con `dataset`). Pendiente: **RFCE** (resumen de la factura de consumo bajo RD$250.000), que incluye
el código de seguridad de la firma → fase 3. Impuestos adicionales, otra moneda, paginación y descuentos
globales se añadirán cuando un origen de emisión los necesite.

## Corrección incluida en la fase 0: barra superior en el teléfono

El icono de instalar la app (2026-10-01) empujaba el avatar 26 px fuera de la pantalla a 390 px. Ahora el
bloque de la empresa se encoge y recorta su nombre con «…», el de iconos no se encoge y la barra usa menos
relleno bajo 640 px. Verificado sin desbordamiento a 1280, 390, 360 y 320 px.
