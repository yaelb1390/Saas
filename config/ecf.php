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

    // Conservación del XML firmado [IT §9].
    'retention_years' => 10,

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
        'rounding_rule' => 'La «regla de redondeos» que cita [FMT nota 11] y si la DGII tolera diferencias de céntimos en los totales.',
    ],

    /*
     * Proveedor por defecto para una empresa recién configurada. `fake` no envía nada a ningún
     * sitio: sirve para preparar y probar sin riesgo. El real se elige por empresa.
     */
    'default_provider' => 'fake',
];
