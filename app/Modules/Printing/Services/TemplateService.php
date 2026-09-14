<?php

declare(strict_types=1);

namespace App\Modules\Printing\Services;

use App\Modules\Printing\DTOs\SaveTemplateData;
use App\Modules\Printing\Models\PrintTemplate;
use App\Modules\Printing\Support\DocumentType;
use Illuminate\Support\Facades\DB;

/**
 * El diseño de cada tipo de documento: el editor visual escribe aquí, y DocumentRenderer lee de aquí.
 *
 * EL CONTRATO DEL `layout` VIVE EN `layoutPorDefecto()`. Es la única definición de qué campos existen
 * en una plantilla —logo, textos, qué se muestra u oculta, tamaños, alineación, QR, código de barras—.
 * Cambiar la forma de ese array es cambiar el contrato entre el editor y el renderizador: los dos
 * deben seguir leyendo las mismas claves.
 */
final class TemplateService
{
    /**
     * El diseño de fábrica de un tipo de documento. Todo encendido por lo razonable: una factura
     * lleva NCF por defecto, un ticket de venta no —lo dice DocumentType::category()—.
     *
     * @return array<string, mixed>
     */
    public function layoutPorDefecto(string $documentType): array
    {
        $esFiscal = DocumentType::category($documentType) === DocumentType::CATEGORY_INVOICE;

        return [
            'logo' => ['enabled' => true, 'size' => 'md'],
            'header_text' => '',
            'footer_text' => '¡Gracias por su preferencia!',
            'show_company_name' => true,
            'show_phone' => true,
            'show_address' => true,
            'show_rnc' => $esFiscal,
            'show_ncf' => $esFiscal,
            // sm | md | lg — tamaño del texto del cuerpo.
            'font_size' => 'md',
            // left | center | right.
            'align_header' => 'center',
            'align_totals' => 'right',
            // El origen del código: 'reference' es el número del propio documento (venta, préstamo…).
            'qr' => ['enabled' => false, 'source' => 'reference'],
            'barcode' => ['enabled' => false, 'source' => 'reference'],
            // Textos libres que el usuario añade en el editor: [{label, value}].
            'extra_fields' => [],
        ];
    }

    /**
     * Crea o actualiza una plantilla. Si se marca como predeterminada, le quita esa marca a
     * cualquier otra plantilla DEL MISMO TIPO —una por tipo de documento, no una global—.
     */
    public function guardar(?PrintTemplate $existente, SaveTemplateData $datos): PrintTemplate
    {
        // El layout se completa sobre el de fábrica: si el editor no manda una clave (una versión
        // vieja del formulario, un campo nuevo que aún no existía), la plantilla no se queda con un
        // hueco que rompa al renderizador.
        $layout = array_replace($this->layoutPorDefecto($datos->documentType), $datos->layout);

        return DB::transaction(function () use ($existente, $datos, $layout): PrintTemplate {
            if ($datos->isDefault) {
                PrintTemplate::query()
                    ->where('document_type', $datos->documentType)
                    ->when($existente, fn ($q, $e) => $q->whereKeyNot($e->id))
                    ->update(['is_default' => false]);
            }

            $atributos = [
                'document_type' => $datos->documentType,
                'name' => $datos->name,
                'paper_size' => $datos->paperSize,
                'layout' => $layout,
                'is_default' => $datos->isDefault,
            ];

            if ($existente) {
                $existente->update($atributos);

                return $existente;
            }

            return PrintTemplate::create($atributos);
        });
    }

    public function borrar(PrintTemplate $template): void
    {
        $template->delete();
    }

    /** La plantilla predeterminada de un tipo, dentro de la empresa activa (aislada por CompanyScope). */
    public function predeterminadaPara(string $documentType): ?PrintTemplate
    {
        return PrintTemplate::query()
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->first();
    }
}
