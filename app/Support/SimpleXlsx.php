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
    /** Ancho de columna en Excel, medido en caracteres. Ver `cols()`. */
    private const MIN_WIDTH = 10.0;

    private const MAX_WIDTH = 40.0;

    private const PADDING = 2.0;

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function write(array $headers, iterable $rows): string
    {
        // Hace falta el total de filas ANTES de escribir la hoja (para el rango de la tabla), así
        // que un generador de un solo paso no serviría: se materializa una vez. Todo lo que ya
        // llama aquí manda una Collection de Eloquent (::get()->map()), así que no cuesta nada.
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        $lastCol = self::columnLetter(max(0, count($headers) - 1));
        $lastRow = 1 + count($rows);

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::RELS);
        $zip->addFromString('xl/workbook.xml', self::WORKBOOK);
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headers, $rows, $lastCol, $lastRow));
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', self::SHEET_RELS);
        $zip->addFromString('xl/tables/table1.xml', self::table($headers, $lastCol, $lastRow));

        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private static function sheet(array $headers, array $rows, string $lastCol, int $lastRow): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .self::cols($headers, $rows)
            .'<sheetData>';

        $r = 1;
        $xml .= self::row($headers, $r++);
        foreach ($rows as $row) {
            $xml .= self::row(array_values((array) $row), $r++);
        }

        $xml .= '</sheetData>';
        // La "tabla dinámica" que se ve en Excel (filtros en el encabezado, franjas alternas, azul
        // del estilo de la app): un Table de verdad, no solo celdas sueltas. Se referencia por su
        // relación (rId1 → xl/tables/table1.xml) y no por estilos de celda a mano: así el color y
        // el bandeado los pinta Excel con su estilo nativo "TableStyleMedium2" (azul, el mismo que
        // usa por defecto al insertar una tabla con Ctrl+T), sin tener que reconstruir un tema.
        $xml .= '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>';

        return $xml.'</worksheet>';
    }

    /**
     * Ancho de cada columna, calculado por el contenido: sin esto, Excel abre el archivo con el
     * ancho por omisión (unos 8 caracteres) y "Vencimiento" o el nombre de un cliente largo salen
     * cortados hasta que alguien ensancha la columna a mano.
     *
     * Se mide el texto más largo de esa columna —encabezado incluido— y se acota entre un mínimo
     * (que quepa el encabezado corto, tipo "Total") y un máximo (que una nota larga no estire la
     * hoja entera). `customWidth="1"` es obligatorio: sin él, Excel ignora el `width` y usa el suyo.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private static function cols(array $headers, array $rows): string
    {
        $anchos = [];
        foreach ($headers as $i => $nombre) {
            $anchos[$i] = mb_strlen((string) $nombre);
        }

        foreach ($rows as $fila) {
            foreach (array_values((array) $fila) as $i => $valor) {
                $largo = mb_strlen((string) ($valor ?? ''));
                if ($largo > ($anchos[$i] ?? 0)) {
                    $anchos[$i] = $largo;
                }
            }
        }

        $xml = '<cols>';
        foreach ($anchos as $i => $largo) {
            $ancho = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $largo + self::PADDING));
            $col = $i + 1;
            $xml .= '<col min="'.$col.'" max="'.$col.'" width="'.round($ancho, 2).'" customWidth="1"/>';
        }

        return $xml.'</cols>';
    }

    /**
     * La definición de la tabla: qué rango cubre, cómo se llama cada columna (tiene que coincidir
     * exactamente con el texto del encabezado ya escrito) y con qué estilo se pinta.
     *
     * @param  array<int, string>  $headers
     */
    private static function table(array $headers, string $lastCol, int $lastRow): string
    {
        $ref = 'A1:'.$lastCol.$lastRow;

        $columns = '';
        foreach ($headers as $i => $name) {
            $text = htmlspecialchars((string) $name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $columns .= '<tableColumn id="'.($i + 1).'" name="'.$text.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'id="1" name="Tabla1" displayName="Tabla1" ref="'.$ref.'" totalsRowShown="0">'
            .'<autoFilter ref="'.$ref.'"/>'
            .'<tableColumns count="'.count($headers).'">'.$columns.'</tableColumns>'
            .'<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" '
            .'showRowStripes="1" showColumnStripes="0"/>'
            .'</table>';
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
        .'<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        .'</Types>';

    private const SHEET_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>'
        .'</Relationships>';

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
