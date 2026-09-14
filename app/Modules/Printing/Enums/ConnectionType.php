<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

/**
 * Cómo llega BMIA a la impresora física.
 *
 * `Browser` es un caso especial: no hay nada que emparejar ni recordar, es «la impresora que el
 * sistema operativo ya tiene instalada», elegida en el diálogo nativo de imprimir. Las otras tres
 * exigen que la impresora esté registrada aquí con lo que hace falta para hablarle directamente.
 */
enum ConnectionType: string
{
    case Bluetooth = 'bluetooth';
    case Usb = 'usb';
    case Network = 'network';
    case Browser = 'browser';

    public function label(): string
    {
        return match ($this) {
            self::Bluetooth => 'Bluetooth',
            self::Usb => 'USB',
            self::Network => 'Red / Wi-Fi',
            self::Browser => 'Del sistema (navegador)',
        };
    }

    /** El icono de Icons.php asociado, para no repetir el match en cada vista. */
    public function icon(): string
    {
        return match ($this) {
            self::Bluetooth => 'bluetooth',
            self::Usb => 'usb',
            self::Network => 'wifi',
            self::Browser => 'printer',
        };
    }
}
