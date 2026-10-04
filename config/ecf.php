<?php

declare(strict_types=1);

/*
 * Facturación Electrónica DGII (e-CF).
 *
 * REGLA DE ESTE ARCHIVO: aquí solo entra lo que está escrito en un documento oficial de la DGII, con
 * su referencia. Nada de memoria ni de «así lo hace todo el mundo». Lo que la documentación no aclara
 * NO se rellena: se marca como pendiente y el paso que lo necesita se bloquea.
 *
 * Siglas de las referencias (títulos y fechas exactas en resources/dgii/ecf/v1.0/manifest.json):
 *   [DT]  Descripción Técnica Servicios DGII
 *   [IT]  Informe Técnico e-CF v1.0
 *   [FIR] Firmado de e-CF
 *   [FMT] Formato Comprobante Fiscal Electrónico (e-CF) v1.0
 *
 * La DGII cambia contenido «sin cambio de versión» [FMT p.1]: por eso cada documento emitido guarda la
 * fecha de los esquemas con los que se validó (`schema_date`), no solo «1.0».
 */
return [

    'spec' => [
        // Valor del campo <Version> [FMT p.5].
        'version' => '1.0',
        'path' => resource_path('dgii/ecf/v1.0'),
        'manifest' => resource_path('dgii/ecf/v1.0/manifest.json'),
    ],

    /*
     * Ambientes [DT pp.6–7]. Se eligen SOLO por el segmento de la URL; el host `fc` es el de las
     * facturas de consumo bajo el umbral (RFCE). Solo producción tiene validez fiscal.
     */
    'environments' => [
        'pruebas' => ['segment' => 'testecf', 'label' => 'Pre-certificación (pruebas)', 'fiscal' => false],
        'certificacion' => ['segment' => 'certecf', 'label' => 'Certificación', 'fiscal' => false],
        'produccion' => ['segment' => 'ecf', 'label' => 'Producción', 'fiscal' => true],
    ],

    'hosts' => [
        'ecf' => 'https://ecf.dgii.gov.do',
        'fc' => 'https://fc.dgii.gov.do',
    ],

    /*
     * Servicios de la DGII para emisores [DT pp.8–25]. Ruta = host + /{segmento del ambiente} + path.
     * POST siempre multipart/form-data con el campo `xml`; token Bearer (RFC 6750).
     */
    'services' => [
        'seed' => ['host' => 'ecf', 'path' => '/autenticacion/api/autenticacion/semilla'],              // GET [DT p.9]
        'validate_seed' => ['host' => 'ecf', 'path' => '/autenticacion/api/autenticacion/validarsemilla'], // POST [DT p.10]
        'reception' => ['host' => 'ecf', 'path' => '/recepcion/api/facturaselectronicas'],               // POST [DT p.13]
        'rfce_reception' => ['host' => 'fc', 'path' => '/recepcionfc/api/recepcion/ecf'],               // POST [DT p.16]
        'result' => ['host' => 'ecf', 'path' => '/consultaresultado/api/consultas/estado'],             // GET ?trackid= [DT p.22]
    ],

    /*
     * Estados de la consulta de resultado [DT p.25]. El RFCE responde Aceptado / Aceptado condicional
     * / Rechazado de forma síncrona y sin TrackId [DT p.15]; su `codigo` no tiene tabla publicada, así
     * que se interpreta por el texto de `estado`.
     */
    'result_codes' => [
        0 => 'no_encontrado',
        1 => 'aceptado',
        2 => 'rechazado',
        3 => 'en_proceso',
        4 => 'aceptado_condicional',
    ],

    'http' => [
        // Vercel corta la función en ~10 s: cada llamada tiene que caber holgadamente.
        'connect_timeout' => 3,
        'timeout' => 8,
        // El token dura «1 hora por el momento» [DT p.8]: se renueva un poco antes de `expira`.
        'token_refresh_margin_seconds' => 120,
    ],

    // Reintentos de envío/consulta (minutos desde el último intento). Ajuste de BMIA.
    'retry_backoff_minutes' => [1, 5, 15, 60, 240, 720],

    /*
     * Tipos de e-CF [IT §6.1]. `xsd` apunta al esquema oficial vendorizado.
     */
    'types' => [
        31 => ['label' => 'Factura de Crédito Fiscal Electrónica', 'xsd' => 'ecf-31.xsd'],
        32 => ['label' => 'Factura de Consumo Electrónica', 'xsd' => 'ecf-32.xsd'],
        33 => ['label' => 'Nota de Débito Electrónica', 'xsd' => 'ecf-33.xsd'],
        34 => ['label' => 'Nota de Crédito Electrónica', 'xsd' => 'ecf-34.xsd'],
        41 => ['label' => 'Compras Electrónico', 'xsd' => 'ecf-41.xsd'],
        43 => ['label' => 'Gastos Menores Electrónico', 'xsd' => 'ecf-43.xsd'],
        44 => ['label' => 'Regímenes Especiales Electrónico', 'xsd' => 'ecf-44.xsd'],
        45 => ['label' => 'Gubernamental Electrónico', 'xsd' => 'ecf-45.xsd'],
        46 => ['label' => 'Exportación Electrónico', 'xsd' => 'ecf-46.xsd'],
        47 => ['label' => 'Pagos al Exterior Electrónico', 'xsd' => 'ecf-47.xsd'],
    ],

    // Otros esquemas oficiales (resumen de consumo, acuse, anulación, aprobación comercial, semilla).
    'schemas' => [
        'rfce' => 'rfce-32.xsd',
        'arecf' => 'arecf.xsd',
        'anecf' => 'anecf.xsd',
        'acecf' => 'acecf.xsd',
        'semilla' => 'semilla.xsd',
    ],

    /*
     * ERRATAS DE LOS XSD OFICIALES (detectadas el 2026-10-03).
     *
     * Tres esquemas publicados por la DGII no compilan con un validador estándar de XML Schema
     * (libxml); la DGII usa .NET, que es más permisivo. Los archivos oficiales NO se tocan (su huella
     * sha256 debe seguir coincidiendo con lo publicado): para validar se genera al momento una copia
     * corregida en el directorio temporal, aplicando solo estas correcciones. Si una errata deja de
     * aplicarse (la DGII corrigió el archivo), el validador se detiene y avisa para quitarla.
     *
     *   · copy_simple_type: copia LITERAL una definición desde otro XSD oficial (no se escribe a mano).
     *   · replace: sustitución exacta de texto.
     */
    'schema_errata' => [
        'ecf-31.xsd' => [[
            'type' => 'copy_simple_type',
            'name' => 'IndicadorServicioTodoIncluidoType',
            'from' => 'ecf-32.xsd',
            'reason' => 'El XSD del 31 usa el tipo IndicadorServicioTodoIncluidoType pero no lo define. La definición es idéntica en los XSD oficiales 32, 33, 34 y 44; se copia del 32.',
        ]],
        'acecf.xsd' => [[
            'type' => 'replace',
            'search' => '(?:',
            'replace' => '(',
            'reason' => 'Grupos no capturantes «(?:…)» de .NET: no existen en las expresiones de XML Schema. Para validar, un grupo normal es equivalente.',
        ]],
        'rfce-32.xsd' => [[
            'type' => 'replace',
            'search' => '(?:',
            'replace' => '(',
            'reason' => 'Grupos no capturantes «(?:…)» de .NET: no existen en las expresiones de XML Schema. Para validar, un grupo normal es equivalente.',
        ]],
    ],

    /*
     * e-NCF: 13 posiciones = serie «E» + tipo (2) + secuencial (10) [IT §7].
     */
    'encf' => [
        'series' => 'E',
        'sequence_digits' => 10,
    ],

    /*
     * Factura de consumo (32): por debajo de este total se envía solo el Resumen (RFCE) y se
     * conserva el e-CF completo; desde este monto, el e-CF completo [DT pp.12,15; IT §9].
     */
    'consumo' => [
        'rfce_threshold' => '250000.00',
    ],

    /*
     * ITBIS por ítem [FMT, sección B «Detalle de Bienes o Servicios», campo 4
     * <IndicadorFacturacion>; sección A «Totales», campos 93–103].
     *   0 No facturable · 1 ITBIS 1 (18 %) · 2 ITBIS 2 (16 %) · 3 ITBIS 3 (0 %) · 4 Exento.
     * Con precios que ya incluyen ITBIS (<IndicadorMontoGravado> = 1, [FMT campo 7]) el monto
     * gravado es la suma de los ítems de esa tasa ÷ (1 + tasa), y el ITBIS = monto gravado × tasa
     * [FMT campos 93 y 101]. Monto total = gravado + exento + ITBIS [FMT campo 110].
     */
    'itbis' => [
        'rates' => [
            1 => '18',
            2 => '16',
            3 => '0',
        ],
        'decimals' => 2,
        /*
         * [FMT nota 11] dice «se debe aplicar la regla de redondeos» para los montos de 16 enteros
         * y 2 decimales, pero ninguno de los documentos revisados la define. Mitad hacia arriba es
         * lo que ya usa BMIA para la serie B; queda configurable y en pendientes hasta confirmarlo.
         */
        'rounding' => 'half_up',
    ],

    /*
     * Códigos del encabezado y del detalle [FMT sección A, campos 8, 9 y 12; sección B, campo 9].
     */
    'codes' => [
        'income_type' => [
            '01' => 'Ingresos por operaciones (no financieros)',
            '02' => 'Ingresos financieros',
            '03' => 'Ingresos extraordinarios',
            '04' => 'Ingresos por arrendamientos',
            '05' => 'Ingresos por venta de activo depreciable',
            '06' => 'Otros ingresos',
        ],
        'payment_type' => [1 => 'Contado', 2 => 'Crédito', 3 => 'Gratuito'],
        'payment_form' => [
            1 => 'Efectivo',
            2 => 'Cheque / transferencia / depósito',
            3 => 'Tarjeta de débito / crédito',
            4 => 'Venta a crédito',
            5 => 'Bonos o certificados de regalo',
            6 => 'Permuta',
            7 => 'Nota de crédito',
            8 => 'Otras formas de pago',
        ],
        'good_or_service' => [1 => 'Bien', 2 => 'Servicio'],
        // [FMT sección F «Información de referencia», campo 4 <CodigoModificacion>].
        'modification' => [
            1 => 'Anula el NCF modificado',
            2 => 'Corrige texto del comprobante fiscal modificado',
            3 => 'Corrige montos del NCF modificado',
            4 => 'Reemplazo de NCF emitido en contingencia',
            5 => 'Referencia factura de consumo electrónica',
        ],
    ],

    /*
     * Indicador de facturación que admite cada tipo [FMT notas 50 y 51]: en 43, 44 y 47 todos los
     * ítems van exentos (4); en 46, a ITBIS tasa cero (3). Los demás tipos admiten cualquiera.
     */
    'allowed_indicators' => [
        43 => [4],
        44 => [4],
        46 => [3],
        47 => [4],
    ],

    /*
     * Retenciones por ítem [FMT sección B, campos 5–7; totales 116–117]. El régimen de percepción no
     * está vigente [FMT nota 52]: el indicador siempre es 1 («R», retención).
     */
    'retention_indicator' => 1,

    /*
     * Nota de crédito (34) [FMT encabezado, campo 5 <IndicadorNotaCredito>]: 0 si se emite dentro
     * de estos días calendario desde el e-CF afectado, 1 si después (no da derecho a rebajar ITBIS).
     */
    'credit_note_itbis_days' => 30,

    /*
     * Formatos de fecha que exige el XSD (FechaValidationType, DateTimeValidationType). La fecha y
     * hora de la firma va en GMT-4 [FIR; FMT §G–H]: desfase fijo, no la zona del servidor.
     */
    'formats' => [
        'date' => 'd-m-Y',
        'datetime' => 'd-m-Y H:i:s',
        'signature_utc_offset' => '-04:00',
    ],

    // Conservación del XML firmado [IT §9].
    'retention_years' => 10,

    /*
     * Ajuste de BMIA (no es una regla de la DGII): con cuántos días de antelación se avisa de que el
     * certificado de firma va a vencer.
     */
    'certificate_warning_days' => 30,

    /*
     * Código de seguridad: «los primeros seis (6) dígitos del hash generado en el SignatureValue»
     * [DT p.28; IT p.36]. El texto no define el algoritmo de ese «hash»; el ejemplo oficial («dcp79q»)
     * es alfanumérico, compatible con los primeros 6 caracteres del propio SignatureValue (base64) y NO
     * con un hash hexadecimal (no lleva «p» ni «q»). Se implementa esa lectura como INTERPRETACIÓN,
     * pendiente de confirmar en pre-certificación (el servicio de timbre de la DGII lo valida).
     */
    'security_code' => [
        'strategy' => 'signature_value_prefix',
        'length' => 6,
    ],

    /*
     * Plazos de contingencia [IT §19, Decreto 587-24]. Solo se usan para AVISAR; el procedimiento
     * en sí sigue la documentación oficial.
     */
    'contingency' => [
        'send_within_hours_after_connectivity' => 72,
        'paper_series_max_days' => 15,
        'regularize_within_days' => 30,
        'legend' => 'e-CF emitido en modalidad de contingencia',
    ],

    /*
     * Lo que la documentación revisada NO aclara. Mientras siga aquí, lo que dependa de ello no se
     * implementa por suposición.
     */
    'pending_verification' => [
        'security_code_hash' => 'Algoritmo exacto del «hash» del SignatureValue para el código de seguridad [DT p.28; IT p.36].',
        'psfe_signer' => 'Si un proveedor certificado firma con su certificado o con el del contribuyente (delegación).',
        'psfe_admin_user' => 'Si con proveedor certificado hace falta el «Usuario Administrador e-CF».',
        'emitter_receiver_endpoints' => 'Servicios emisor↔receptor: «Descripción Técnica Servicios Emisores Electrónicos», sin revisar.',
        'tip' => 'Cómo se declara la propina legal (10 %) en el e-CF. Hoy queda fuera del documento (fase 5a).',
        'rounding_rule' => 'La «regla de redondeos» que cita [FMT nota 11] y si la DGII tolera diferencias de céntimos en los totales.',
    ],

    /*
     * Proveedor por defecto para una empresa recién configurada. `fake` no envía nada a ningún
     * sitio: sirve para preparar y probar sin riesgo. El real se elige por empresa.
     */
    'default_provider' => 'fake',

    /*
     * Respuesta del proveedor de prueba (valores de ProviderOutcome), para recorrer cada camino:
     * received, accepted, accepted_conditional, rejected, in_process, not_found, transient_error,
     * permanent_error. `sequence_used` es el `secuenciaUtilizada` de un rechazo simulado [DT p.24].
     * Nunca responde en producción.
     */
    'fake' => [
        'send' => 'received',
        'summary' => 'accepted',
        'query' => 'accepted',
        'sequence_used' => true,
    ],
];
