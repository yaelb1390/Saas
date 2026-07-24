<?php

declare(strict_types=1);

namespace App\Support;

use ZipArchive;

/**
 * Generador mínimo de XLSX (Office Open XML) con ZipArchive nativo — sin dependencias de composer,
 * para no romper el build serverless. Un .xlsx es un zip de XML: aquí se arman las partes mínimas
 * (content types, relaciones, workbook y una hoja) con celdas de texto (inlineStr) o numéricas.
 *
 * Pensado para exportar tablas del panel (encabezados + filas). Escribe a un archivo temporal y
 * devuelve su ruta; el llamador la envía como descarga.
 */
final class SimpleXlsx
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function write(array $headers, iterable $rows): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::RELS);
        $zip->addFromString('xl/workbook.xml', self::WORKBOOK);
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headers, $rows));

        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private static function sheet(array $headers, iterable $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $r = 1;
        $xml .= self::row($headers, $r++);
        foreach ($rows as $row) {
            $xml .= self::row(array_values((array) $row), $r++);
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private static function row(array $cells, int $rowNumber): string
    {
        $xml = '<row r="'.$rowNumber.'">';
        foreach ($cells as $i => $value) {
            $ref = self::columnLetter($i).$rowNumber;
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
            } else {
                $text = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    /** Índice de columna (0-based) a letra de Excel: 0→A, 25→Z, 26→AA. */
    private static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'</Types>';

    private const RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    private const WORKBOOK = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets>'
        .'</workbook>';

    private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'</Relationships>';
}
