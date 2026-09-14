<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Requests;

use App\Modules\Printing\Support\DocumentType;
use App\Modules\Printing\Support\PaperSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guardar una plantilla de impresión: el tipo de documento, su tamaño de papel y el diseño entero
 * —logo, textos, campos visibles, alineación, QR, código de barras— en `layout`.
 */
final class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(DocumentType::keys())],
            'name' => ['required', 'string', 'max:120'],
            'paper_size' => ['required', Rule::in(PaperSize::keys())],
            'is_default' => ['nullable', 'boolean'],

            'layout' => ['nullable', 'array'],
            'layout.logo.enabled' => ['nullable', 'boolean'],
            'layout.logo.size' => ['nullable', Rule::in(['sm', 'md', 'lg'])],
            'layout.header_text' => ['nullable', 'string', 'max:255'],
            'layout.footer_text' => ['nullable', 'string', 'max:255'],
            'layout.show_company_name' => ['nullable', 'boolean'],
            'layout.show_phone' => ['nullable', 'boolean'],
            'layout.show_address' => ['nullable', 'boolean'],
            'layout.show_rnc' => ['nullable', 'boolean'],
            'layout.show_ncf' => ['nullable', 'boolean'],
            'layout.font_size' => ['nullable', Rule::in(['sm', 'md', 'lg'])],
            'layout.align_header' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'layout.align_totals' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'layout.qr.enabled' => ['nullable', 'boolean'],
            'layout.qr.source' => ['nullable', 'string', 'max:40'],
            'layout.barcode.enabled' => ['nullable', 'boolean'],
            'layout.barcode.source' => ['nullable', 'string', 'max:40'],
            'layout.extra_fields' => ['nullable', 'array', 'max:20'],
            'layout.extra_fields.*.label' => ['required_with:layout.extra_fields', 'string', 'max:60'],
            'layout.extra_fields.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'document_type' => 'tipo de documento',
            'paper_size' => 'tamaño de papel',
        ];
    }
}
