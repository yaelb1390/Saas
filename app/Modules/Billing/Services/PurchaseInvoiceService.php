<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\ElectronicInvoicingHook;
use App\Modules\Billing\Models\PurchaseInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de comprobantes de compra (606). El aislamiento por empresa lo pone el trait
 * BelongsToCompany (company_id se fija solo al crear dentro del tenant activo).
 */
final class PurchaseInvoiceService
{
    public function __construct(private readonly ElectronicInvoicingHook $electronic) {}

    /** Campos del formulario que se guardan tal cual. */
    private const FIELDS = [
        'provider_tax_id', 'provider_tax_id_kind', 'provider_name', 'ncf', 'ncf_modified',
        'goods_services_type', 'invoice_date', 'payment_date', 'amount', 'itbis', 'itbis_retenido',
        'isr_retenido', 'isc', 'other_taxes', 'tip', 'payment_method',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $file, ?int $userId): PurchaseInvoice
    {
        $attrs = $this->attributes($data);
        $attrs['user_id'] = $userId;
        $attrs['extraction_status'] = 'manual';

        if ($file !== null) {
            $attrs = [...$attrs, ...$this->fileAttributes($file)];
        }

        // Comprobante electrónico de compras (41) o gastos menores (43): lo emite la propia empresa.
        $kind = $data['ecf_kind'] ?? null;
        $isService = (bool) ($data['ecf_is_service'] ?? false);

        if ($kind === null || $kind === '') {
            return PurchaseInvoice::create($attrs);
        }

        return DB::transaction(function () use ($attrs, $kind, $isService): PurchaseInvoice {
            $purchase = new PurchaseInvoice($attrs);

            // Modo real: el e-NCF es el NCF de la compra. Si no se puede emitir, lanza.
            $electronico = $this->electronic->replacePurchaseNcf($purchase, $kind, $isService);

            if ($electronico !== null) {
                $purchase->ncf = $electronico['ncf'];
                $purchase->forceFill(['electronic_invoice_id' => $electronico['electronic_invoice_id']]);
            }

            if (blank($purchase->ncf)) {
                throw ValidationException::withMessages([
                    'ncf' => 'Escribe el NCF: la facturación electrónica no está en modo real, así que el comprobante sigue siendo el de papel.',
                ]);
            }

            $purchase->save();
            $this->electronic->afterPurchaseCreated($purchase, $kind, $isService);

            return $purchase;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseInvoice $invoice, array $data, ?UploadedFile $file): PurchaseInvoice
    {
        $attrs = $this->attributes($data);

        if ($file !== null) {
            $attrs = [...$attrs, ...$this->fileAttributes($file)];
        }

        $invoice->update($attrs);

        return $invoice->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attrs = [];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $attrs[$field] = $data[$field];
            }
        }

        return $attrs;
    }

    /**
     * @return array<string, mixed>
     */
    private function fileAttributes(UploadedFile $file): array
    {
        return [
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => (string) $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'file_content' => base64_encode((string) file_get_contents((string) $file->getRealPath())),
        ];
    }
}
