<?php

declare(strict_types=1);

namespace App\Modules\Printing\Services;

use App\Models\User;
use App\Modules\Printing\DTOs\SavePrinterData;
use App\Modules\Printing\Enums\PrinterStatus;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrinterPreference;

/**
 * Alta, edición y baja de impresoras, y quién es la predeterminada de cada usuario.
 *
 * El registro en sí es sencillo a propósito —es una ficha, no un dispositivo con el que se dialoga
 * desde el servidor—: el servidor nunca habla directamente con el hardware. Quien manda los bytes a
 * una impresora Bluetooth es el navegador (ver `resources/js/printing/bluetooth.js`); aquí solo se
 * guarda lo necesario para que el navegador la reconozca y la vuelva a usar.
 */
final class PrinterRegistry
{
    public function crear(SavePrinterData $datos, ?User $creador = null): Printer
    {
        return Printer::create([
            'created_by' => $creador?->id,
            // Explícito y no fiado al valor por omisión de la columna: una unidad recién creada no
            // reelee sus columnas por defecto, así que sin esto el objeto en memoria quedaría con
            // `last_status` en null hasta la próxima consulta —el mismo tropiezo de «tracks_serials
            // en null tras crear»— y cualquier código que lo lea antes de refrescar vería un estado
            // que no existe en el enum.
            'last_status' => PrinterStatus::Disponible,
            'name' => $datos->name,
            'manufacturer' => $datos->manufacturer,
            'model' => $datos->model,
            'connection_type' => $datos->connectionType,
            'bt_device_id' => $datos->btDeviceId,
            'bt_service_uuid' => $datos->btServiceUuid,
            'bt_characteristic_uuid' => $datos->btCharacteristicUuid,
            'address' => $datos->address,
            'paper_size' => $datos->paperSize,
            'custom_width_mm' => $datos->customWidthMm,
            'custom_height_mm' => $datos->customHeightMm,
            'settings' => $datos->settings,
        ]);
    }

    public function actualizar(Printer $printer, SavePrinterData $datos): Printer
    {
        $printer->update([
            'name' => $datos->name,
            'manufacturer' => $datos->manufacturer,
            'model' => $datos->model,
            'connection_type' => $datos->connectionType,
            'bt_device_id' => $datos->btDeviceId,
            'bt_service_uuid' => $datos->btServiceUuid,
            'bt_characteristic_uuid' => $datos->btCharacteristicUuid,
            'address' => $datos->address,
            'paper_size' => $datos->paperSize,
            'custom_width_mm' => $datos->customWidthMm,
            'custom_height_mm' => $datos->customHeightMm,
            'settings' => $datos->settings,
        ]);

        return $printer;
    }

    /**
     * Retira la impresora. Soft-delete: el historial de impresiones sigue apuntando a ella, y un
     * trabajo de hace un año no debe perder de vista en qué salió.
     */
    public function borrar(Printer $printer): void
    {
        $printer->delete();
    }

    public function marcarEstado(Printer $printer, PrinterStatus $estado): Printer
    {
        $printer->update([
            'last_status' => $estado,
            'last_seen_at' => now(),
        ]);

        return $printer;
    }

    /**
     * Fija (o quita, con null) la impresora predeterminada de ESTE usuario. No toca la de nadie más:
     * la térmica está atornillada a una caja, no a la empresa entera.
     */
    public function marcarPredeterminada(User $usuario, int $companyId, ?Printer $printer): PrinterPreference
    {
        return PrinterPreference::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $usuario->id],
            ['default_printer_id' => $printer?->id],
        );
    }

    public function predeterminadaDe(User $usuario, int $companyId): ?Printer
    {
        return PrinterPreference::where('company_id', $companyId)
            ->where('user_id', $usuario->id)
            ->first()
            ?->defaultPrinter;
    }
}
