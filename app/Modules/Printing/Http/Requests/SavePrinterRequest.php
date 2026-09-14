<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Requests;

use App\Modules\Printing\Enums\ConnectionType;
use App\Modules\Printing\Support\PaperSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edición de una impresora. Vale para las dos —el controlador decide si crea o actualiza—
 * porque los campos y sus reglas son exactamente los mismos.
 */
final class SavePrinterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'connection_type' => ['required', Rule::enum(ConnectionType::class)],
            'paper_size' => ['required', Rule::in(PaperSize::keys())],
            'custom_width_mm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'custom_height_mm' => ['nullable', 'integer', 'min:1', 'max:2000'],

            // Solo tienen sentido cuando connection_type = bluetooth, pero no se exigen con
            // `required_if`: el dispositivo puede registrarse ANTES de emparejar —«configurar
            // manualmente» del onboarding— y completarse al conectar desde el navegador.
            'bt_device_id' => ['nullable', 'string', 'max:255'],
            'bt_service_uuid' => ['nullable', 'string', 'max:80'],
            'bt_characteristic_uuid' => ['nullable', 'string', 'max:80'],

            'address' => ['nullable', 'string', 'max:120'],

            'settings' => ['nullable', 'array'],
            'settings.margin_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'settings.orientation' => ['nullable', Rule::in(['portrait', 'landscape'])],
            'settings.quality' => ['nullable', Rule::in(['draft', 'normal', 'high'])],
            'settings.default_copies' => ['nullable', 'integer', 'min:1', 'max:20'],
            'settings.auto_cut' => ['nullable', 'boolean'],
            'settings.color' => ['nullable', Rule::in(['bw', 'color'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'connection_type' => 'tipo de conexión',
            'paper_size' => 'tamaño de papel',
        ];
    }
}
