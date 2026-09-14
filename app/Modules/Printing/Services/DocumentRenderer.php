<?php

declare(strict_types=1);

namespace App\Modules\Printing\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Printing\DTOs\PrintableDocumentData;
use App\Modules\Printing\Models\PrintTemplate;
use App\Modules\Printing\Support\DocumentType;
use App\Modules\Printing\Support\EscPosBuilder;
use App\Modules\Printing\Support\PaperSize;

/**
 * Convierte (plantilla + datos genéricos) en algo que se puede imprimir de verdad, por los dos
 * caminos que tiene el Centro de Impresión:
 *
 *   - `renderHtml()`  → el HTML que va al diálogo del navegador (`window.print()`) o a la vista
 *     previa. Es el camino de siempre, el que ya usa todo BMIA.
 *   - `renderEscPos()` → los comandos que necesita una térmica Bluetooth, en base64: el servidor los
 *     arma porque conoce el documento, y el navegador solo los transmite por Web Bluetooth —el
 *     servidor no puede hablarle directo a un dispositivo emparejado con el teléfono de otra persona.
 *
 * NO CONOCE VENTAS, PRÉSTAMOS NI NINGÚN OTRO MODELO DE NEGOCIO. Solo conoce `PrintableDocumentData`
 * —el contrato genérico— y `layout` —el contrato de la plantilla—. Eso es lo que permite que
 * cualquier módulo futuro se enchufe con un adaptador pequeño (como `SaleTicketAdapter`) en vez de
 * tocar este servicio.
 */
final class DocumentRenderer
{
    /**
     * @return array{html: string, ancho_mm: int, es_rollo: bool}
     */
    public function renderHtml(PrintTemplate $template, Company $company, PrintableDocumentData $data, bool $paraPdf = false): array
    {
        [$anchoMm, $esRollo] = $this->medidas($template);

        $html = view('panel.printing.partials.document', [
            'layout' => $template->layout,
            'paperSize' => $template->paper_size,
            'anchoMm' => $anchoMm,
            'esRollo' => $esRollo,
            'company' => $company,
            'data' => $data,
            'typeLabel' => DocumentType::label($template->document_type),
            'pdf' => $paraPdf,
        ])->render();

        return ['html' => $html, 'ancho_mm' => $anchoMm, 'es_rollo' => $esRollo];
    }

    /** El mismo documento, en comandos ESC/POS, listo para transmitir por Bluetooth. Base64. */
    public function renderEscPos(PrintTemplate $template, Company $company, PrintableDocumentData $data): string
    {
        $layout = $template->layout;
        $b = new EscPosBuilder;
        $b->init();

        $b->align($layout['align_header'] ?? 'center');

        if ($layout['show_company_name'] ?? true) {
            $b->bold()->line($company->nombreParaDocumentos())->bold(false);
        }
        if (($layout['show_rnc'] ?? false) && $company->tax_id) {
            $b->line('RNC: '.$company->tax_id);
        }
        if (($layout['show_address'] ?? true) && $company->address) {
            $b->line((string) $company->address);
        }
        if (($layout['show_phone'] ?? true) && $company->phone) {
            $b->line('Tel: '.$company->phone);
        }
        if (! empty($layout['header_text'])) {
            $b->line((string) $layout['header_text']);
        }

        $b->align('center')->line(str_repeat('-', 32));
        $b->bold()->line(strtoupper(DocumentType::label($template->document_type)))->bold(false);
        if ($data->reference) {
            $b->line($data->reference);
        }
        $b->line(str_repeat('-', 32))->align('left');

        foreach ($data->meta as $m) {
            $b->line(($m['label'] ?? '').': '.($m['value'] ?? ''));
        }

        if (! empty($data->lines)) {
            $b->line(str_repeat('-', 32));
            foreach ($data->lines as $linea) {
                $izq = trim(($linea['qty'] ?? '').' '.($linea['description'] ?? ''));
                $der = (string) ($linea['amount'] ?? '');
                $b->line($this->columna($izq, $der));
                if (! empty($linea['detail'])) {
                    $b->line('  '.$linea['detail']);
                }
            }
        }

        if (! empty($data->totals)) {
            $b->line(str_repeat('-', 32));
            foreach ($data->totals as $t) {
                if ($t['emphasis'] ?? false) {
                    $b->bold();
                }
                $b->line($this->columna((string) ($t['label'] ?? ''), (string) ($t['value'] ?? '')));
                if ($t['emphasis'] ?? false) {
                    $b->bold(false);
                }
            }
        }

        foreach ($layout['extra_fields'] ?? [] as $campo) {
            $b->line(($campo['label'] ?? '').': '.($campo['value'] ?? ''));
        }

        if ($data->note || ! empty($layout['footer_text'])) {
            $b->align('center')->feed(1);
            if ($data->note) {
                $b->line($data->note);
            }
            if (! empty($layout['footer_text'])) {
                $b->line((string) $layout['footer_text']);
            }
        }

        if (($layout['qr']['enabled'] ?? false) && $data->reference) {
            $b->align('center')->feed(1)->qr($data->reference);
        }
        if (($layout['barcode']['enabled'] ?? false) && $data->reference) {
            $b->align('center')->feed(1)->barcode($data->reference);
        }

        $b->feed(3)->cut();

        return $b->toBase64();
    }

    /**
     * Dos columnas dentro de los 32 caracteres típicos de un rollo de 80mm: el texto a la izquierda,
     * el importe pegado a la derecha. Es lo más parecido a una tabla que entiende una térmica, que no
     * sabe de HTML ni de CSS.
     */
    private function columna(string $izquierda, string $derecha, int $ancho = 32): string
    {
        $espacio = max(1, $ancho - strlen($izquierda) - strlen($derecha));

        return $izquierda.str_repeat(' ', $espacio).$derecha;
    }

    /**
     * @return array{0: int, 1: bool} [ancho en mm, es rollo continuo]
     */
    private function medidas(PrintTemplate $template): array
    {
        if (PaperSize::exists($template->paper_size) && $template->paper_size !== 'custom') {
            return [PaperSize::widthMm($template->paper_size), PaperSize::isContinuousRoll($template->paper_size)];
        }

        // Personalizado: el ancho de la plantilla no guarda medidas propias —las trae la impresora
        // que la use—, así que se asume el ancho térmico más común como base razonable de la vista
        // previa cuando no hay una impresora concreta todavía eligiéndolo.
        return [80, true];
    }
}
