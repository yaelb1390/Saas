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

## Fase 3 — Certificado digital y firma (2026-10-03)

### Certificado (`Signature/CertificateVault`, tabla `electronic_certificates`)

- Al subirlo se comprueba de verdad: se abre con su contraseña (`openssl_pkcs12_read`), la clave
  corresponde al certificado y está vigente. Un certificado que no sirve no se guarda.
- El `.p12` se **cifra** con la clave de la aplicación (`Crypt`) antes de tocar el disco privado
  `fiscal_documents` (`FISCAL_DOCUMENTS_DISK`; en producción, S3/R2 privado). La contraseña va con cast
  `encrypted`. Ninguno de los dos va a la auditoría ni se serializa.
- Se abre **solo en memoria** (`LoadedCertificate`): la clave privada no se escribe en claro en ningún
  sitio; `__debugInfo` la oculta y serializarla lanza un error.
- Reemplazar desactiva el anterior sin borrarlo (para saber con cuál se firmó cada documento).
- Estado: vigente / por vencer (aviso a 30 días, ajuste de BMIA) / vencido / aún no válido.
- Pantalla: sección «Certificado digital» (permiso `ecf.configure`, máx. 10 intentos por minuto).

### Firma (`Signature/XmlSigner`)

Perfil verificado [FIR pp.2–3; FMT §G–H]: XMLDSig envuelta, `Reference URI=""`, C14N inclusiva 20010315,
RSA-SHA256, resumen SHA-256, `KeyInfo` solo con `X509Certificate`, `<Signature>` sin prefijo como último
hijo (el `xs:any` del XSD) y `<FechaHoraFirma>` GMT-4 escrita antes de firmar.

- **Desvío del plan:** `robrichards/xmlseclibs` 3.1.5 en vez de `selective/xmldsig`. El ejemplo oficial
  de la DGII usa el segundo, pero está **abandonado** y su autor remite al primero. Mismo perfil de firma.
- Comprobado en pruebas: la firma se verifica de forma independiente; cambiar un dato la invalida; el XML
  firmado **valida contra el XSD oficial sin marcador**.

### Código de seguridad (`Signature/SecurityCode`)

[DT p.28; IT p.36]: «primeros seis dígitos del hash generado en el SignatureValue». Implementado como los
primeros 6 caracteres del SignatureValue: es la única lectura compatible con el ejemplo oficial («dcp79q»,
alfanumérico; un hash hexadecimal no tendría «p» ni «q»), y el XSD del RFCE solo exige 6 caracteres
cualesquiera (`.{6}`). **Es una interpretación**: sigue en `pending_verification` hasta confirmarla con el
servicio de timbre de la DGII en pre-certificación.

### Resumen de factura de consumo (RFCE)

- `EcfXmlGenerator::sendsSummary()`: tipo 32 con total < RD$250.000 [DT pp.12,15].
- `generateRfce()`: subconjunto del encabezado y totales del 32 (sin detalle) + `CodigoSeguridadeCF` del 32
  ya firmado, dentro del `Encabezado`. Se firma sin `FechaHoraFirma` (su XSD no la tiene). Validado contra
  `rfce-32.xsd` (con su errata).
- El e-CF 32 completo se genera, firma y conserva igual: el RFCE solo cambia qué se envía.

### Migración de la fase

```
2026_10_03_100200_create_electronic_certificates_table
```

### Avisos de seguridad de dependencias (preexistentes, fuera de esta fase)

`composer audit` reporta 28 avisos en 7 paquetes que ya estaban en el proyecto (guzzle y commonmark con
alguno de gravedad alta; dompdf, livewire, phpseclib, laravel/framework, flysystem). Ninguno en xmlseclibs.
Conviene actualizarlos en una tarea aparte.

## Fase 4 — Proveedores, envío, estados y contingencia (2026-10-03)

### El circuito (`Application/ElectronicInvoiceService`)

Único punto por el que pasa un e-CF; nadie más habla con la DGII ni con un proveedor.

1. **Validación con un e-NCF provisional** antes de tocar la secuencia: un error de datos no quema números.
2. **Sin certificado no se reserva número** (no se podría firmar).
3. Número definitivo → fila `electronic_invoices` → XML original guardado → firma (y RFCE firmado si el 32
   va por resumen) → `pendiente_envio` → envío inmediato.
4. La respuesta se guarda (append-only) y se traduce a estado.

Cada cambio de estado lo valida `EcfStatus::canTransitionTo()` (un salto ilegal lanza excepción) y queda en
`electronic_invoice_audit_logs` con usuario e IP.

| Resultado del proveedor | Estado del documento |
|---|---|
| Recibido con TrackId | `recibido` → se consulta 1 min después |
| Aceptado / aceptado condicional (DGII 1 / 4) | `aceptado` / `aceptado_condicional` |
| Rechazado (DGII 2) | `rechazado`; con `secuenciaUtilizada = false` el e-NCF vuelve al uso [DT p.24] |
| En proceso / no encontrado (DGII 3 / 0) | sigue igual, se reconsulta con espera creciente |
| Sin red, tiempo agotado, 5xx | `pendiente_envio` + contingencia abierta; espera 1, 5, 15, 60, 240, 720 min |
| Credenciales, petición mal formada, proveedor no configurado | `error` (necesita intervención; reintentable) |

- **Reutilizar un e-NCF devuelto**: el índice único de `electronic_invoices` es **parcial** (excluye los
  `rechazado`), para que el documento nuevo lleve el mismo número y el rechazado se conserve como historia.
- El XML firmado se guarda byte a byte con su sha256 **antes** de enviarlo; un reenvío manda exactamente
  esos bytes, y si la huella no coincide no se envía (queda en `error`). Un documento que falla de forma
  inesperada no corta la tanda del procesador.
- **Pendiente (fase 6):** si la función se corta justo entre «enviando» y la respuesta, el documento queda
  en `enviando` y el procesador no lo retoma (reenviar a ciegas podría duplicar). El Diagnóstico los
  señalará para reconciliarlos con la consulta de TrackIds.

### Proveedores (`Providers/`)

- `fake` (por defecto): no envía nada. Su respuesta se elige en `config('ecf.fake')` para recorrer cada
  camino. **En producción se niega** (el documento queda en `error`, nunca «aceptado» de mentira).
- `dgii` (Escenario A, `DgiiDirectProvider` + `Dgii/DgiiClient`): semilla → firma → `validarsemilla` →
  token Bearer, guardado **cifrado** en caché por empresa **y ambiente** hasta 2 min antes de `expira`
  (nunca se supone que dura una hora); ante un 401 se renueva una vez. Recepción e-CF (`trackId`), recepción
  RFCE (host `fc.`, síncrona) y consulta de resultado (códigos 0–4). El ambiente sale del segmento de la
  URL (`testecf`/`certecf`/`ecf`). La semilla se lee sin red y rechazando DOCTYPE/entidades (anti-XXE).
  Tiempos: 3 s de conexión, 8 s en total.
- `psfe`: contrato listo; responde «no configurado» hasta elegir el proveedor certificado.

### Contingencia (`Contingency/ContingencyService`)

Un fallo de comunicación abre una contingencia «DGII no disponible» por empresa y ambiente (si no hay otra
abierta); la primera respuesta que vuelve a llegar la cierra. Los documentos emitidos mientras está abierta
quedan ligados a ella (leyenda en la representación impresa, fase 6). Plazos oficiales en
`config('ecf.contingency')` [IT §19]; el aviso de las 72 h es de la fase 6.

### Envíos y consultas pendientes

`php artisan ecf:procesar-pendientes --presupuesto=8`, expuesto en `GET /tareas/ecf-procesar` (exige
`Authorization: Bearer <CRON_SECRET>`; sin él, 403). Procesa los más antiguos primero hasta agotar el
presupuesto de tiempo. **Configurar en cron-job.org cada 5 minutos** con esa cabecera (el plan Hobby de
Vercel solo permite crons diarios). Sin la tabla migrada, no hace nada.

### Conservación

Las cinco tablas nuevas no tienen clave foránea hacia `companies` y están en `TenantDataPurger::KEPT`:
ni la purga ni el borrado de la empresa las tocan (probado). Archivos en
`ecf/{empresa}/{ambiente}/{aaaa}/{mm}/{e-NCF}/{tipo}-{n}.xml` del disco privado.

### Migración de la fase

```
2026_10_03_100300_create_electronic_invoices_tables
```

### Cobertura

`EcfEmissionTest` (19 pruebas): circuito completo con RFCE y con TrackId + consulta, contingencia abierta y
cerrada, rechazo con y sin reutilización del número, índice parcial, XML alterado en disco, documento inválido y falta de
certificado sin consumir número, proveedor de prueba bloqueado en producción, respuestas y bitácora
inmodificables, transiciones ilegales, conservación al borrar la empresa, proveedor DGII con respuestas
simuladas (token cifrado y reutilizado, solo `testecf`, códigos 0–4, 503, XXE, sin red) y el secreto del cron.

## Fase 5a — e-CF desde las ventas facturadas (2026-10-04)

Facturación A4, POS/Venta rápida, Cotización → Factura y Mostrador facturan todos con
`Billing\Services\InvoiceService::issueForSale`. Ahí se engancha el e-CF, una sola vez para los cuatro.

### Contrato e inversión de dependencias

`Billing\Contracts\ElectronicInvoicingHook` lo declara Billing; lo implementa
`ElectronicInvoicing\Application\Sources\BillingBridge`. Sin el módulo, `NoElectronicInvoicing` (no hace
nada). Billing no conoce el módulo de e-CF.

### Modo de emisión por empresa (`Domain/EmissionMode`, columna `emission_mode`)

| Modo | Ambiente | Qué pasa al facturar |
|---|---|---|
| apagado (por omisión) | cualquiera | Solo serie B, como siempre |
| en paralelo (`sombra`) | pruebas / certificación | La factura B sale igual; además se prepara un e-CF con la misma venta en un punto de guardado propio. Si falla, se deshace solo él y queda el suceso `ecf.shadow_failed` |
| real | producción | El e-CF **es** el comprobante (`invoices.ncf` = e-NCF, sin secuencia B). Si no se puede emitir, no hay factura (`InvoiceException` con el motivo), como hoy sin secuencia B |

- En pruebas/certificación un e-CF no tiene validez fiscal, por eso ahí no puede sustituir a la serie B.
- Un modo que no cuadra con el ambiente cuenta como «apagado». Encender exige certificado activo.
- El envío a la DGII se hace **después de confirmar** la transacción de la venta (`DB::afterCommit`): una
  venta revertida no deja un e-CF enviado, y el cobro nunca espera ni se cae por la DGII (si falla, queda
  pendiente y lo retoma el procesador). `ElectronicInvoiceService` se separa en `prepare()` + `send()`.
- `invoices.electronic_invoice_id` enlaza la factura con su e-CF; el e-CF apunta a la factura
  (`source_type = invoice`).

### De la venta al e-CF (`Application/Sources/SaleDocumentMapper`)

- Tipo: B01 → 31, B02 → 32, B15 → 45 [IT §6.1]. Las notas (B03/B04) no salen de una venta: en modo real se
  rechazan con su mensaje (fase 5b), nunca caen en silencio a la serie B.
- Precios con ITBIS incluido (como cobra la venta); el indicador de cada línea es el del producto.
- **Descuento global** del ticket: se reparte entre las líneas en proporción a su importe y el céntimo
  sobrante va a la última, así la suma de líneas es exactamente lo cobrado.
- **Propina**: fuera del e-CF (el total declarado es el de la venta sin propina; se descuenta de la forma de
  pago de mayor importe). Cómo se declara la propina legal en el e-CF → `pending_verification`.
- Formas de pago desde el desglose real de la venta: efectivo 1, cheque/transferencia 2, tarjeta 3,
  crédito 4. Venta a crédito → `TipoPago` 2; sin fecha de vencimiento en la venta, no se inventa una.
- Cantidad con 3 decimales (venta por peso): se acepta si el tercero es 0; si no, el validador la rechaza
  (no se redondea una cantidad vendida a escondidas).

### Pantalla

Tarjeta «Emisión en ventas y facturas» (permiso `ecf.configure`) y contadores reales de pendientes,
aceptados y rechazados.

### Migración

```
2026_10_04_100000_add_ecf_emission_mode   (settings.emission_mode + invoices.electronic_invoice_id)
```

### Cobertura

`EcfSaleEmissionTest` (9): apagado, en paralelo (con éxito y con fallo sin afectar a la factura B), real
(con la DGII simulada en `ecf`/`fc` de producción; serie B intacta), real sin poder emitir (sin factura ni
número gastado), tipo no soportado, descuento global + propina, y el selector de modo.

## Fase 5b — Notas de crédito (34) y débito (33) (2026-10-04)

Hasta ahora BMIA no tenía notas de crédito/débito: «anular» marcaba la factura B y la mandaba al 608. Un
e-CF aceptado **no se anula**: se revierte con una nota de crédito que lo referencia [FMT sección F].

- **Anular una factura cuyo comprobante es un e-CF** (modo real): `InvoiceService::cancel` llama a
  `beforeCancel` del contrato, que emite una **nota de crédito 34 por el total**, con las mismas líneas de
  la venta y `CodigoModificacion` 1 «Anula el NCF modificado». Si la nota no se puede emitir (sin secuencia
  E34, datos inválidos…), la factura **no** se anula y se dice por qué. `cancel` pasa a ser transaccional.
  Si la DGII rechazó el e-CF original, no hay nada que revertir y se anula sin nota.
- **En paralelo**: la anulación B sigue igual (608) y se genera la nota de prueba contra el e-CF de prueba;
  si falla, no estorba (suceso `ecf.shadow_failed`).
- **Notas por importe** (`InvoiceNoteService::forAmount`, botón «Nota de crédito o débito» en Facturas,
  permiso `ecf.issue`): una línea con el importe (ITBIS incluido), su indicador y el motivo;
  `CodigoModificacion` 3 «Corrige montos». La suma de notas de crédito no puede superar el total del e-CF
  [FMT campo 110 d)] y se rechaza con su mensaje.
- La nota va al **mismo ambiente** que el e-CF que modifica (si la empresa cambió de ambiente, se rechaza) y
  se envía tras confirmar la transacción. Se guarda con `source_type` `credit_note`/`debit_note` y
  `source_id` = la factura: la tabla `invoices` (y su índice único por venta) no cambia.
- **Pendiente de verificar** (`pending_verification.reports_607_608`): si con e-CF siguen haciendo falta el
  607/608 y si un e-CF revertido va en el 608.

Cobertura: 5 pruebas más en `EcfSaleEmissionTest` (anulación real con nota y su XML referenciando el
e-NCF; anulación imposible sin E34; anulación en paralelo; notas por importe con el tope del crédito;
permisos).

## Fase 5c — Compras (41) y Gastos menores (43) (2026-10-04)

Cuando se compra a quien no puede dar comprobante (proveedor informal), lo emite la propia empresa: hoy
con los talonarios B11/B13 escritos a mano en «Compras 606»; con e-CF, los tipos 41 y 43 [IT §6.1].

- En el formulario de «Compras 606» (solo con el módulo): «Comprobante electrónico propio» → Compras (41)
  o Gastos menores (43), y «Es un servicio».
  - **Real**: el e-NCF es el NCF de la compra; no hace falta escribirlo ni subir papel. Si el e-CF no se
    puede emitir, la compra no se guarda y se dice por qué.
  - **En paralelo**: se escribe el NCF en papel como siempre y se genera además un e-CF de prueba.
  - **Apagado**: si se pide sin escribir el NCF, se explica que hay que escribirlo (no se inventa nada).
- `PurchaseDocumentMapper`:
  - en el 41 el **proveedor informal va como «comprador»** del XML (lo exige el XSD); la empresa es el emisor;
  - el monto del 606 es la base **sin** ITBIS. Con ITBIS, la línea va gravada al 18 % y el total calculado
    tiene que cuadrar con monto + ITBIS; sin ITBIS, exenta. El 43 solo admite exentos [FMT nota 50];
  - ITBIS e ISR retenidos del 606 → retenciones de la línea (ISR solo en servicios, lo valida el
    validador); la forma de pago lleva lo efectivamente pagado (total − retenciones).
- Contrato: `replacePurchaseNcf` / `afterPurchaseCreated`; columna `purchase_invoices.electronic_invoice_id`
  (migración `2026_10_04_100100`). El e-CF apunta a la compra (`source_type = purchase_invoice`).

Cobertura: 4 pruebas más en `EcfSaleEmissionTest` (41 real con la DGII simulada y el proveedor en el XML;
41 en paralelo; 43 con ITBIS rechazado sin guardar la compra; apagado sin NCF).

### Pendiente de la fase 5 (a la fase 6)

- Mostrar el estado del e-CF (aceptado, rechazado, pendiente) junto a cada factura y compra, y en el PDF
  la representación impresa con QR (fase 6).
- Datos fiscales del emisor y cambio de ambiente desde la pantalla (hoy se precargan de «Mi empresa»): van
  en el asistente de la fase 6.

## Fase 6a — Configuración del emisor (2026-10-04)

Tarjeta «Datos fiscales y ambiente» (permiso `ecf.configure`): RNC/cédula (dígito verificador), razón social
y nombre comercial (máx. 150, XSD), dirección (máx. 100, XSD), provincia y municipio, correo, teléfono,
usuario administrador e-CF, ambiente y proveedor.

- **Provincia y municipio** con los códigos oficiales leídos del XSD (`Xml/TerritoryCatalog`: la
  enumeración `ProvinciaMunicipioType` de ecf-31.xsd y su comentario; 582 códigos, 32 provincias). El
  municipio tiene que ser de la provincia elegida.
- **Pasar a producción** exige marcar «Confirmo que la DGII ya autorizó a mi empresa» y un proveedor real
  (el de prueba no envía nada). **Cambiar de ambiente apaga la emisión**: el modo real se enciende a
  propósito, nunca se hereda.
- Estado de BMIA recalculado (`syncStatus`): sin datos o sin certificado → No configurado; En pruebas / En
  certificación según el ambiente; en producción Autorizado (lo confirmó el usuario) y Activo con el modo
  real.

## Fase 6b — Documentos en pantalla (2026-10-04)

- «Documentos electrónicos»: lista con búsqueda (e-NCF, cliente, RNC) y filtros (estado, tipo).
- Ficha: datos, archivos con su huella (descarga verificada; si el archivo cambió, 409), respuestas de la
  DGII, bitácora (permiso `ecf.audit`), y las acciones «Reintentar envío» (`ecf.send`) y «Consultar
  resultado» (`ecf.query`), solo cuando la máquina de estados las permite.
- En Facturas, cada factura enseña el estado de su e-CF (o del de prueba, rotulado así) con enlace a la
  ficha, en una sola consulta.

## Fase 6c — Representación impresa con timbre (2026-10-04)

[IT §18; DT pp.40–42]. En el PDF A4 de una factura cuyo comprobante es un e-CF (modo real): el tipo en
palabras, el e-NCF, el vencimiento de la secuencia, el **QR** (25 mm, por encima del mínimo de 22 mm), el
**código de seguridad bajo el QR**, la fecha de firma, la leyenda de contingencia si aplica y un aviso
si el ambiente no es de producción. En paralelo no se toca: el documento es la factura B.

- URL del QR por ambiente (`testecf`/`certecf`/`ecf`), con los valores **leídos del XML firmado** (lo que
  recibió la DGII, no la base):
  - e-CF por recepción: `https://ecf.dgii.gov.do/{amb}/consultatimbre?rncemisor&rnccomprador&encf&fechaemision&montototal&fechafirma&codigoseguridad`;
  - 32 bajo RD$250.000: `https://fc.dgii.gov.do/{amb}/consultatimbrefc?rncemisor&encf&montototal&codigoseguridad`.
  - Codificación como el ejemplo oficial: espacio `%20`, «:» sin codificar (`fechafirma=10-10-2020%2009:00:00`).
- QR en SVG (sin GD) con `bacon/bacon-qr-code` (ya instalado; ahora declarado en `composer.json`).
  Verificado: dompdf lo dibuja como vectores.
- **Hallazgo — versión del QR:** el DT pide versión 8, pero la URL completa de un e-CF (~200 caracteres)
  no cabe en la versión 8 en modo byte (máx. 192). Se intenta la 8 y, si no cabe, la menor que la contenga
  (en la práctica la 9); nunca se recorta la URL. En `pending_verification.qr_version`.
- **Ticket de 80 mm** (decisión del usuario, 2026-10-04): el mismo timbre en los tres caminos del ticket
  de venta, solo si el comprobante es un e-CF (con NCF en papel, nada cambia):
  - `sales/receipt` (HTML que imprime el POS) y `sales/receipt-pdf` (dompdf): parcial
    `sales/partials/ecf-timbre` con tipo, e-NCF, vencimiento, firma, QR de 25 mm y código de seguridad. El
    alto del rollo suma 175 pt y sigue saliendo en una sola página (probado).
  - Centro de Impresión: `PrintableDocumentData` gana un `stamp` (sello fiscal) genérico; `SaleTicketAdapter`
    lo llena y `DocumentRenderer` lo pinta en HTML y en ESC/POS (QR nativo de la térmica, `GS ( k`)
    **aunque la plantilla no tenga QR**: es un requisito del comprobante, no decoración.
  - Sin fusionar con el sistema A4: el ticket sigue siendo su propia plantilla.
- **Fuera de esta fase:** las notas 33/34 y las compras 41/43 no tienen todavía PDF propio.

## Fase 6d — Diagnóstico y avisos (2026-10-04)

`Application/Diagnostics` (pantalla «Diagnóstico», permiso `ecf.view`): cada chequeo dice Correcto,
Advertencia o Error y, si no está bien, **cómo solucionarlo**. Solo lee; nunca llama a la DGII.

| Chequeo | Error / advertencia cuando… |
|---|---|
| Datos fiscales | falta RNC válido, razón social o dirección |
| Certificado | no hay (error si se emite), por vencer (30 días), vencido, aún no válido |
| Proveedor | PSFE sin conectar con emisión encendida; el de prueba con emisión encendida |
| Secuencias 31, 32, 34 | sin secuencia vigente en el ambiente (error si se emite); quedan ≤ 50 números |
| Envíos interrumpidos | documento en «enviando» más de 10 min (la función se cortó): consultar antes de reenviar |
| Documentos con error / rechazados (7 días) | hay alguno |
| Contingencia | abierta (error a las 72 h [IT §19]) |
| Procesador de pendientes | hay pendientes y el cron externo no corre desde hace 20 min (o nunca) |
| XSD, extensiones de PHP, almacenamiento | huella distinta, falta una extensión, el disco privado no escribe/lee |

**Campana del panel** (`AlertService`): con el módulo, un aviso «N avisos de facturación electrónica» que
lleva al diagnóstico; cuenta documentos en error, envíos interrumpidos, contingencia abierta y certificado
por vencer o vencido. **Una** consulta con subconsultas y sin preguntar al catálogo (si las tablas aún no
existen, falla, se captura y no hay aviso), dentro de la caché de un minuto de la campana. Con cinco
consultas el dashboard en frío pasaba de 37; así queda en 33 (tope de `QueryBudgetTest` de 33 a 34,
documentado allí).

Con esto queda cubierto el «Pendiente (fase 6)» de los documentos atascados en «enviando»: se señalan para
consultarlos antes de reenviar (reenviar a ciegas podría duplicar).

## Fases 6e–6h — Cifras, pasos, auditoría y avisos (2026-10-04)

- **6e Cifras** (`Application/EmissionStats`): «Emisión de los últimos 30 días» en el resumen del ambiente
  actual — documentos, % de aceptación (aceptados / resueltos), total e ITBIS (rechazados y anulados no
  suman), barras apiladas por día (aceptados, rechazados, pendientes; Chart.js como el dashboard) y
  desglose por tipo. Dos consultas agrupadas.
- **6f Pasos para emitir** (`Application/SetupWizard`): los 8 pasos del plan (datos fiscales →
  secuencias → certificado → pruebas en paralelo → validación técnica → pruebas aceptadas → certificación
  → producción) con su estado **calculado** de lo que existe —no guardado aparte, que se quedaría viejo— y
  enlace a la tarjeta que resuelve cada uno. 7 y 8 dependen de la DGII: se dan por hechos al cambiar de
  ambiente, y producción exige la confirmación expresa.
- **6g Auditoría** (permiso `ecf.audit`): la bitácora de todos los e-CF de la empresa, filtrable por
  e-NCF, usuario, acción y fechas; enlaza a cada documento. Las filas solo se añaden.
- **6h Avisos**: cada cambio de estado dispara `Events\EcfStatusChanged` **después de confirmar la
  transacción** (gancho para n8n/webhooks/CRM). Oyente `NotifyRejectedEcf`: si la DGII rechaza un e-CF de
  **producción**, correo al dueño con el motivo y el enlace (`emails/ecf-rejected`). En pruebas y
  certificación no (los rechazos son parte de probar). Se apaga con `ECF_EMAIL_ON_REJECTION=false`.
  WhatsApp/Telegram: el evento queda listo para conectarlos cuando se pida.

## Fase 7a — La empresa como receptora (2026-10-04)

Fuente: **Descripción Técnica Servicios Emisores Electrónicos** [DTEE], DGII 29/05/2026 (descargada de la
página oficial; sha256 `d532598461fc65bb731d37b32d57af83763649921a91aeb9d5c93359834e41a1`). Era el documento
que faltaba (`pending_verification.emitter_receiver_endpoints`).

### Servicios (públicos, por empresa)

`https://{host}/api/ecf-receptor/{clave}` + los recursos del estándar:

| Recurso | Método | Qué hace |
|---|---|---|
| `/fe/autenticacion/api/semilla` | GET | `SemillaModel` con un valor único (vale 5 min, un solo uso) |
| `/fe/autenticacion/api/validacioncertificado` | POST `xml` | semilla firmada → token (1 h), JSON o XML según `Accept`; fechas `yyyy-MM-ddTHH:mm:ssZ` |
| `/fe/recepcion/api/ecf` | POST `xml` | recibe el e-CF y devuelve el **ARECF firmado** |
| `/fe/aprobacioncomercial/api/ecf` | POST `xml` | recibe la ACECF del comprador sobre un e-CF propio; 200 / 400 |

- `{clave}`: 32 caracteres por empresa (`receiver_key`), no adivinable. Se ven en «Comprobantes recibidos»
  para registrarlas en la Oficina Virtual.
- **No distinguen mayúsculas/minúsculas** [DTEE «Reglas Generales»]: una ruta comodín y el recurso se
  resuelve en minúsculas (también acepta «recepción» con tilde, como aparece en una tabla del propio DTEE).
- La autenticación es opcional en el estándar; BMIA la ofrece, así que por omisión **exige el token** para
  recibir (`ECF_RECEIVER_REQUIRE_AUTH`). Tokens en la caché (base de datos en producción: valen en
  cualquier instancia).
- **Acuse de recibo** [arecf.xsd]: Estado 0 recibido / 1 no recibido; motivo 1 error de especificación
  (no valida contra el XSD de su tipo), 2 error de firma, 3 envío duplicado, 4 RNC comprador no
  corresponde. Siempre firmado y validado contra `arecf.xsd`, también el de «no recibido».
- Todo lo recibido (e-CF y ARECF) se guarda tal cual con su sha256 en el disco privado y se conserva
  (tabla `electronic_received_documents` en `TenantDataPurger::KEPT`).
- Aprobación comercial recibida: valida XSD (`acecf.xsd` con su errata) y firma, exige que el emisor sea la
  empresa, la asocia a su e-CF (`commercial_status/reason/at`, archivo `acecf`) y la anota en la bitácora.

### Hallazgos en la firma (corregidos, afectaban a lo ya hecho)

1. **Espacios en `<SignedInfo>`.** La plantilla de xmlseclibs metía saltos de línea y sangría. El DTEE
   exige firmar sin preservar espacios; quien verifica así (la DGII, en .NET) descartaba esos nodos y la
   firma no cuadraba. Ahora se quitan antes de firmar (y del resto de la firma).
2. **Espacios de nombres de la raíz.** Con C14N inclusivo, `<SignedInfo>` se verifica heredando las
   declaraciones de la raíz (`xmlns:xsi`, `xmlns:xsd`); xmlseclibs lo firmaba suelto, sin ellas. Nuestros
   e-CF no declaran ninguna (no les afectaba), pero **la semilla de la DGII sí**: la autenticación ante la
   DGII habría fallado. Ahora el `SignatureValue` se calcula sobre el `SignedInfo` ya colocado.
   Pruebas de regresión en `EcfSignatureTest`.

Además, el diagnóstico avisa si el **SN del certificado** no corresponde al RNC/cédula del emisor [DTEE
«Firmado de XML»].

### Migración

```
2026_10_05_100000_create_electronic_receiver_tables
```

### Cobertura

`EcfReceiverTest` (8): autenticación (semilla de un solo uso, JSON/XML), recepción con ARECF firmado y
válido, sin token 401, los cuatro motivos de «no recibido», mayúsculas y tilde, clave inexistente 404,
aprobación comercial recibida (200 y 400) y la pantalla de recibidos.

## Fases 7b–7d — Aprobaciones comerciales, envío al comprador y anulación de rangos (2026-10-04)

### 7b — Aprobación comercial que emite la empresa (`Receiver/CommercialApprovalService`)

En «Comprobantes recibidos», cada e-CF recibido se **acepta** o **rechaza (con motivo)**:

1. ACECF [acecf.xsd]: RNC emisor, e-NCF, fecha de emisión, monto, RNC comprador, Estado 1/2, motivo (solo
   si se rechaza: sin etiquetas vacías [DTEE]) y fecha-hora; firmada y validada contra su XSD.
2. A la DGII (`aprobacioncomercial`) [DT pp.31–33]: código 1 aprobada · 2 rechazada.
3. Al emisor: su `urlAceptacion` del directorio [DT pp.37–39], autenticándose en su `urlOpcional` si la
   declara (`Receiver/PeerClient`). Nombre de archivo RNCComprador + e-NCF [DTEE].

Una sola aprobación por documento (permiso `ecf.issue`); lo que falle al enviar queda anotado.

### 7c — Envío del e-CF aceptado al comprador (`Receiver/BuyerDeliveryService`)

[DT p.12] «La recepción del TrackId y haber recibido un estado de validación satisfactorio habilita al emisor
al envío del e-CF al receptor y, en caso de que este no sea electrónico, la entrega de la representación
impresa.» Un e-CF **aceptado** con comprador identificado queda «pendiente»; el procesador de pendientes lo
busca en el directorio y:

- si es receptor electrónico, le envía el XML firmado a su `urlRecepcion` y guarda su acuse (ARECF, archivo
  `arecf_comprador`, con su Estado y motivo);
- si no lo es, «no_electronico» (se le entrega la RI);
- si el proveedor no consulta el directorio (prueba / PSFE), «no_aplica»;
- fallos pasajeros: hasta 5 intentos, luego «error» para revisarlo.

**Interpretación (documentada):** el directorio da el «host del servicio»; el recurso estándar
(`/fe/recepcion/api/ecf`…) se añade si la URL no lo trae ya.

### 7d — Anulación de e-NCF no usados (`Ncf/RangeVoidService`)

Botón «Anular lo no usado» en cada secuencia (permiso `ecf.cancel`, con confirmación): ANECF [anecf.xsd]
con la **cola sin usar** (del próximo número al final del rango; nunca toca lo emitido), firmada y validada,
a la DGII (`anulacionrangos`) [DT pp.34–36]. La secuencia se recorta **solo** si la DGII la procesa; la
anulación queda en la bitácora con el XML y la respuesta tal cual. El DT no publica la tabla de códigos de
respuesta → `pending_verification.range_void_codes`. No existe en certificación.

### Contrato de proveedores

`sendCommercialApproval`, `findReceiver` (devuelve `ReceiverLookup`) y `voidRange`, en DGII directo, PSFE
(«no configurado») y prueba.

### Cobertura

`EcfReceiverTest` +2 (aprobación con proveedor de prueba: ACECF válida y firmada, rechazo con motivo, una
sola vez; con la DGII simulada: aprobación a la DGII y al emisor con su token, y nuestro e-CF aceptado
enviado al comprador electrónico con su ARECF). `EcfEmissionTest` +2 (anulación con ANECF válida; DGII que no
la procesa y permisos).

## Guía de certificación con BMIA

Pasos del proceso de certificación propio [CERT, 19/08/2025] y qué cubre BMIA en cada uno. **BMIA no
certifica ni autoriza a nadie**: los pasos los da la empresa ante la DGII.

| Paso [CERT] | Con BMIA |
|---|---|
| Formulario FI-GDF-016 y postulación en la Oficina Virtual | Lo hace la empresa. Datos fiscales: «Datos fiscales y ambiente» |
| Certificado digital para procesos tributarios | «Certificado digital» (el diagnóstico avisa si el SN no es el RNC) |
| Secuencias de prueba | «Secuencias de e-NCF», ambiente Pre-certificación |
| Set de pruebas de e-CF sin rechazos | Emitir desde Facturación/POS/Compras en modo «en paralelo» o real en pruebas, con proveedor «Directo a la DGII». El formato del set lo entrega la DGII en la Oficina Virtual: no se automatiza sin verlo |
| Aprobaciones comerciales | «Comprobantes recibidos» → Aceptar/Rechazar (7b) |
| Simulación (incluye RFCE) | Facturas de consumo bajo RD$250.000 van solas por RFCE |
| Representación impresa (≤ 10 MB) | PDF A4 y ticket 80 mm con timbre (6c) |
| Pruebas de comunicación: recibir e-CF y devolver ARECF; recibir aprobaciones | Servicios del receptor (7a): registrar las direcciones de «Comprobantes recibidos» |
| URL de producción y declaración jurada | Lo hace la empresa; después: ambiente Producción + confirmación + modo «e-CF en lugar de la serie B» |

## Corrección incluida en la fase 0: barra superior en el teléfono

El icono de instalar la app (2026-10-01) empujaba el avatar 26 px fuera de la pantalla a 390 px. Ahora el
bloque de la empresa se encoge y recorta su nombre con «…», el de iconos no se encoge y la barra usa menos
relleno bajo 640 px. Verificado sin desbordamiento a 1280, 390, 360 y 320 px.
