<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\GoodsServicesType;
use App\Modules\Billing\Support\TaxIdKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta/edición de un comprobante de compra (606). El archivo es obligatorio al crear; opcional al
 * editar (si no se sube uno nuevo, se conserva el existente).
 */
final class StorePurchaseInvoiceRequest extends FormRequest
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
        $kinds = array_map(fn (TaxIdKind $k): string => $k->value, TaxIdKind::cases());
        $types = array_map(fn (GoodsServicesType $t): string => $t->value, GoodsServicesType::cases());
        $methods = ['cash', 'check', 'transfer', 'card', 'credit', 'swap', 'credit_note', 'other'];

        return [
            'provider_tax_id' => ['nullable', 'string', 'max:20'],
            'provider_tax_id_kind' => ['required', Rule::in($kinds)],
            'provider_name' => ['nullable', 'string', 'max:190'],
            'ncf' => ['required', 'string', 'max:30'],
            'ncf_modified' => ['nullable', 'string', 'max:30'],
            'goods_services_type' => ['required', Rule::in($types)],
            'invoice_date' => ['required', 'date'],
            'payment_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'itbis' => ['nullable', 'numeric', 'min:0'],
            'itbis_retenido' => ['nullable', 'numeric', 'min:0'],
            'isr_retenido' => ['nullable', 'numeric', 'min:0'],
            'isc' => ['nullable', 'numeric', 'min:0'],
            'other_taxes' => ['nullable', 'numeric', 'min:0'],
            'tip' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in($methods)],
            // Foto o PDF de la factura. Obligatorio al crear (POST); opcional al editar (PUT/PATCH).
            'file' => [$this->isMethod('post') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Sube la foto o el PDF de la factura.',
            'file.mimes' => 'El archivo debe ser una imagen (JPG/PNG/WEBP) o un PDF.',
            'ncf.required' => 'El NCF es obligatorio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider_tax_id' => 'RNC/Cédula',
            'provider_tax_id_kind' => 'tipo de identificación',
            'provider_name' => 'proveedor',
            'goods_services_type' => 'tipo de bien/servicio',
            'invoice_date' => 'fecha del comprobante',
            'payment_date' => 'fecha de pago',
            'amount' => 'monto facturado',
            'payment_method' => 'forma de pago',
        ];
    }
}
