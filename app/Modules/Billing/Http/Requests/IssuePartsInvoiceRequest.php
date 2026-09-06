<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Facturación desde el mostrador de repuestos: carrito (JSON), tipo de NCF, cliente y pago. El RNC
 * se valida en profundidad en InvoiceService según el tipo (el mensaje fiscal es su responsabilidad).
 */
final class IssuePartsInvoiceRequest extends FormRequest
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
            'cart' => ['required', 'string'],
            'type' => ['required', Rule::enum(NcfType::class)],
            'customer_tax_id' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            /*
             * CON EL COBRO REPARTIDO NO SE PIDE «pagado»: lo entregado por cada vía viaja en
             * `payments` y el total lo suma el servidor. Exigirlo igualmente rechazaba todo cobro
             * dividido antes de llegar al controlador, con un mensaje —«el campo pagado es
             * obligatorio»— que además señalaba a un campo que la pantalla ni siquiera enseña.
             *
             * `required_without` y no `nullable` a secas: sin reparto sigue siendo obligatorio, que
             * es lo que impide registrar una venta al contado sin decir cuánto se recibió.
             */
            'paid' => ['required_without:payments', 'nullable', 'numeric', 'min:0'],
            // El reparto llega como JSON; su contenido lo valida RepartoDePagos, que es quien sabe.
            'payments' => ['nullable', 'string'],

            /*
             * De qué almacén sale la pieza.
             *
             * Se acota a la empresa A MANO, igual que el cliente de aquí abajo: `exists` consulta la
             * tabla sin pasar por el aislamiento por empresa, y sin esto se podría descontar
             * existencia de la empresa de al lado.
             */
            'warehouse_id' => [
                'nullable', 'integer',
                Rule::exists('warehouses', 'id')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->where('is_active', true),
            ],
            // La regla «exists» no pasa por el CompanyScope: se acota a la empresa activa a mano.
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'cart' => 'ticket',
            'type' => 'tipo de comprobante',
            'customer_tax_id' => 'RNC/cédula',
            'customer_name' => 'cliente',
            'paid' => 'pagado',
            'payments' => 'formas de pago',
        ];
    }
}
