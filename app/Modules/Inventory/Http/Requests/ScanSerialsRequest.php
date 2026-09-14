<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta masiva de unidades serializadas por escaneo.
 *
 * El producto y el almacén se acotan a la empresa activa A MANO —`exists` no pasa por el aislamiento
 * por empresa—, o se podría dar de alta contra el producto de la empresa de al lado.
 */
final class ScanSerialsRequest extends FormRequest
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
        $companyId = app(CurrentCompany::class)->id();

        return [
            // Cualquier producto de la empresa. Ya NO se exige que esté marcado «con serie»: el
            // servicio lo marca al vuelo. Escanear series a un producto es la forma de serializarlo.
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],

            // Los seriales llegan como JSON: una lista de objetos, uno por unidad. Su contenido lo
            // valida el servicio, que es quien sabe de duplicados; aquí solo se comprueba que es
            // texto y que no viene vacío.
            'seriales' => ['required', 'string'],

            // El lote comparte estos: condición, color, costo y precio para toda la tanda.
            'condition' => ['nullable', 'string', 'max:30'],
            'color' => ['nullable', 'string', 'max:60'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id' => 'producto',
            'warehouse_id' => 'almacén',
            'seriales' => 'series escaneadas',
        ];
    }
}
