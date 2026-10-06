<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

/**
 * Ciclo de vida de un e-CF dentro de BMIA.
 *
 * `canTransitionTo()` es la única fuente de verdad sobre qué cambio de estado es válido: un salto que
 * no está aquí (p. ej. de «rechazado» a «aceptado») es un error de programación y se rechaza.
 *
 * Los estados finales de la DGII son los de la consulta de resultado [DT p.25]: aceptado (1), rechazado
 * (2), aceptado condicional (4, válido). «Recibido» es el «en proceso» (3): enviado y con TrackId, a la
 * espera de validación. Un rechazado no se corrige: se emite un documento nuevo.
 */
enum EcfStatus: string
{
    case Borrador = 'borrador';
    case Generado = 'generado';
    case XmlGenerado = 'xml_generado';
    case Firmado = 'firmado';
    case PendienteEnvio = 'pendiente_envio';
    case Enviando = 'enviando';
    case Recibido = 'recibido';
    case Aceptado = 'aceptado';
    case AceptadoCondicional = 'aceptado_condicional';
    case Rechazado = 'rechazado';
    case Anulado = 'anulado';
    case Error = 'error';
    case Contingencia = 'contingencia';

    private const TRANSICIONES = [
        'borrador' => ['generado', 'error'],
        'generado' => ['xml_generado', 'error'],
        // A «pendiente_envio» sin pasar por «firmado»: con un proveedor que firma y envía en la misma
        // llamada (PSFE `submitsUnsigned`), BMIA no firma; la firma llega con la respuesta del envío.
        'xml_generado' => ['firmado', 'pendiente_envio', 'error'],
        'firmado' => ['pendiente_envio', 'error'],
        'pendiente_envio' => ['enviando', 'contingencia', 'error'],
        'enviando' => ['recibido', 'aceptado', 'aceptado_condicional', 'rechazado', 'pendiente_envio', 'contingencia', 'error'],
        'recibido' => ['aceptado', 'aceptado_condicional', 'rechazado', 'error'],
        'contingencia' => ['pendiente_envio', 'enviando'],
        // Un error se puede reintentar: volver a firmar o volver a enviar.
        'error' => ['xml_generado', 'firmado', 'pendiente_envio'],
        'aceptado' => [],
        'aceptado_condicional' => [],
        'rechazado' => [],
        'anulado' => [],
    ];

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::TRANSICIONES[$this->value], true);
    }

    public function isFinal(): bool
    {
        return self::TRANSICIONES[$this->value] === [];
    }

    /** Válido ante la DGII: aceptado o aceptado condicional [DT p.25]. */
    public function isValidForDgii(): bool
    {
        return $this === self::Aceptado || $this === self::AceptadoCondicional;
    }

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Generado => 'Generado',
            self::XmlGenerado => 'XML generado',
            self::Firmado => 'Firmado',
            self::PendienteEnvio => 'Pendiente de envío',
            self::Enviando => 'Enviando',
            self::Recibido => 'Recibido por la DGII',
            self::Aceptado => 'Aceptado',
            self::AceptadoCondicional => 'Aceptado condicional',
            self::Rechazado => 'Rechazado',
            self::Anulado => 'Anulado',
            self::Error => 'Error',
            self::Contingencia => 'Contingencia',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Aceptado, self::AceptadoCondicional => 'badge-green',
            self::Rechazado, self::Error => 'badge-red',
            self::PendienteEnvio, self::Enviando, self::Recibido, self::Contingencia => 'badge-amber',
            default => 'badge-gray',
        };
    }
}
