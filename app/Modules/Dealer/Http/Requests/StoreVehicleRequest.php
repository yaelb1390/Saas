<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una unidad.
 *
 * Solo marca y modelo son obligatorios. Un carro llega al patio y hay que registrarlo YA, antes de
 * que alguien copie el chasis o mida el kilometraje: exigir la ficha completa haría que se anotara
 * en un papel, y el papel no está en el sistema.
 */
final class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return [
            'make' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            // El año: ni antes de que existieran los carros ni más de un año en el futuro, que es
            // hasta donde llegan los modelos nuevos que vende un dealer.
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            /*
             * El chasis, único POR EMPRESA.
             *
             * No global: dos dealers pueden llegar a tener el mismo carro en momentos distintos —uno
             * se lo compra al otro—, y un único global impediría registrarlo al segundo.
             */
            'vin' => [
                'nullable', 'string', 'max:32',
                Rule::unique('vehicles', 'vin')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'trim' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:40'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'fuel' => ['nullable', 'string', 'max:30'],
            'transmission' => ['nullable', 'string', 'max:30'],
            'plate' => ['nullable', 'string', 'max:20'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'asking_price' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'acquired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /*
             * La foto. Se limita el tamaño ANTES de tocarla: sin tope, una foto de móvil moderno
             * son diez megas y el recuadrado con GD se come la memoria del proceso.
             *
             * 'image' comprueba que sea una imagen de verdad y no un fichero renombrado, que es por
             * donde se cuela lo que no debería subirse.
             */
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'make.required' => 'Falta la marca.',
            'model.required' => 'Falta el modelo.',
            'vin.unique' => 'Ya tienes registrado un vehículo con ese chasis.',
            'year.max' => 'Ese año no puede ser.',
            'branch_id.exists' => 'Esa sucursal no es de tu empresa.',
            'photo.image' => 'Ese archivo no es una imagen.',
            'photo.max' => 'La foto pesa demasiado; no puede pasar de 8 MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // El chasis se copia de una chapa a mano: llega con espacios y en cualquier caja. Se
        // normaliza ANTES de validar para que el único por empresa compare lo mismo que se guarda.
        if ($this->filled('vin')) {
            $this->merge(['vin' => mb_strtoupper(trim((string) $this->input('vin')))]);
        }
    }
}
