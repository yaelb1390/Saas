<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

/**
 * Construye los comandos ESC/POS —el lenguaje que hablan casi todas las impresoras térmicas— como una
 * cadena binaria. El servidor arma estos bytes porque es quien conoce el documento y la plantilla; el
 * NAVEGADOR es quien los transmite al dispositivo por Bluetooth (`resources/js/printing/bluetooth.js`)
 * — el servidor no puede hablarle directo a un Bluetooth emparejado con el teléfono o la laptop de
 * otra persona.
 *
 * ADVERTENCIA HONESTA: estos comandos siguen el estándar Epson ESC/POS tal como lo documentan la
 * mayoría de los fabricantes (incluidos los clones chinos comunes en el Caribe), pero no hay forma de
 * verificarlos contra una impresora física desde este entorno. Antes de depender de esto en
 * producción, hay que probarlo contra el modelo real que use cada cliente —sobre todo el QR y el
 * código de barras, que son los comandos con más variación entre fabricantes—.
 */
final class EscPosBuilder
{
    private string $buffer = '';

    public function init(): self
    {
        $this->buffer .= "\x1B\x40"; // ESC @ — reinicia la impresora a su estado por defecto.

        return $this;
    }

    /** 0 = izquierda, 1 = centro, 2 = derecha. */
    public function align(string $alineacion): self
    {
        $codigo = match ($alineacion) {
            'center' => "\x01",
            'right' => "\x02",
            default => "\x00",
        };

        $this->buffer .= "\x1B\x61".$codigo; // ESC a n

        return $this;
    }

    public function bold(bool $activo = true): self
    {
        $this->buffer .= "\x1B\x45".($activo ? "\x01" : "\x00"); // ESC E n

        return $this;
    }

    /**
     * sm | md | lg — el tamaño del texto que sigue. Son los multiplicadores de ancho/alto que ESC/POS
     * admite (1x a 8x); se usan valores discretos y no un número libre para que el editor visual
     * ofrezca tres opciones simples, no un campo que confunda.
     */
    public function fontSize(string $tamano): self
    {
        $n = match ($tamano) {
            'lg' => "\x11", // doble ancho y alto
            'sm' => "\x00",
            default => "\x00",
        };

        $this->buffer .= "\x1D\x21".$n; // GS ! n

        return $this;
    }

    public function text(string $texto): self
    {
        $this->buffer .= $texto;

        return $this;
    }

    public function line(string $texto = ''): self
    {
        $this->buffer .= $texto."\n";

        return $this;
    }

    public function feed(int $lineas = 1): self
    {
        $this->buffer .= str_repeat("\n", max(0, $lineas));

        return $this;
    }

    /**
     * Un código QR con el contenido dado. Sigue la secuencia estándar GS ( k («modelo 2») que
     * documentan Epson y la mayoría de sus clones: fijar modelo, tamaño de módulo, corrección de
     * errores, cargar los datos e imprimir.
     */
    public function qr(string $contenido, int $tamanoModulo = 6): self
    {
        if ($contenido === '') {
            return $this;
        }

        $this->buffer .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00"; // Modelo 2.
        $this->buffer .= "\x1D\x28\x6B\x03\x00\x31\x43".chr(max(1, min(16, $tamanoModulo))); // Tamaño.
        $this->buffer .= "\x1D\x28\x6B\x03\x00\x31\x45\x31"; // Corrección de errores: nivel M.

        $datos = $contenido;
        $longitud = strlen($datos) + 3;
        $pL = $longitud & 0xFF;
        $pH = ($longitud >> 8) & 0xFF;
        $this->buffer .= "\x1D\x28\x6B".chr($pL).chr($pH)."\x31\x50\x30".$datos; // Almacenar datos.

        $this->buffer .= "\x1D\x28\x6B\x03\x00\x31\x51\x30"; // Imprimir el símbolo guardado.

        return $this;
    }

    /**
     * Un código de barras CODE39: el más compatible entre fabricantes distintos y el que no exige
     * elegir un submodo antes de los datos, a diferencia de CODE128.
     */
    public function barcode(string $contenido): self
    {
        if ($contenido === '') {
            return $this;
        }

        // GS k m d1...dn NUL — m=4 selecciona CODE39. Termina en NUL, no en longitud, en esta variante.
        $this->buffer .= "\x1D\x6B\x04".strtoupper($contenido)."\x00";

        return $this;
    }

    /** GS V — corte. `parcial` deja una pestaña de papel sin cortar del todo, para arrancar a mano. */
    public function cut(bool $parcial = true): self
    {
        $this->buffer .= "\x1D\x56".($parcial ? "\x01" : "\x00");

        return $this;
    }

    public function toBinaryString(): string
    {
        return $this->buffer;
    }

    /** Lo que viaja al navegador: bytes crudos codificados en base64 dentro del JSON de la respuesta. */
    public function toBase64(): string
    {
        return base64_encode($this->buffer);
    }
}
