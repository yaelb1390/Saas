<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

/**
 * En qué punto está una empresa con la facturación electrónica.
 *
 * Es un estado de BMIA, no de la DGII: «Autorizado» solo lo marca el usuario después de que la DGII
 * le haya autorizado de verdad. Completar la configuración nunca equivale a estar autorizado.
 */
enum SetupStatus: string
{
    case NoConfigurado = 'no_configurado';
    case Configurado = 'configurado';
    case EnPruebas = 'en_pruebas';
    case EnCertificacion = 'en_certificacion';
    case Autorizado = 'autorizado';
    case Activo = 'activo';
    case Suspendido = 'suspendido';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::NoConfigurado => 'No configurado',
            self::Configurado => 'Configurado',
            self::EnPruebas => 'En pruebas',
            self::EnCertificacion => 'En certificación',
            self::Autorizado => 'Autorizado',
            self::Activo => 'Activo',
            self::Suspendido => 'Suspendido',
            self::Error => 'Error',
        };
    }

    /** Clase de la insignia: el color solo dice si hay que actuar, no decora. */
    public function badge(): string
    {
        return match ($this) {
            self::Activo, self::Autorizado => 'badge-green',
            self::Error, self::Suspendido => 'badge-red',
            self::EnPruebas, self::EnCertificacion => 'badge-amber',
            self::NoConfigurado, self::Configurado => 'badge-gray',
        };
    }
}
