<?php

declare(strict_types=1);

namespace App\Modules\Printing\Services;

use App\Models\User;
use App\Modules\Core\Models\Company;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Support\PrintableModules;

/**
 * El cerebro del botón «Imprimir» global: dado un módulo, ¿en cuál impresora sale esto?
 *
 * EL ORDEN DE DECISIÓN, y por qué es ese orden:
 *   1. La impresora ASIGNADA a ese módulo (Ventas → térmica de la caja). Es la más específica: si la
 *      empresa se tomó el trabajo de decirlo, gana sobre cualquier preferencia personal.
 *   2. La PREDETERMINADA del usuario que está imprimiendo. Sin asignación de módulo, se usa la que
 *      esa persona marcó como suya —la de SU caja—.
 *   3. `null` — ninguna de las dos aplica, así que se cae al diálogo del navegador: el sistema
 *      operativo decide, como ha sido siempre.
 *
 * El mapa módulo→impresora vive en `companies.settings['printing']['modules']` y no en una tabla
 * propia: es exactamente el tipo de interruptor que `settings` ya aloja (ver Company::usesFeature()),
 * y evita una tabla puente para siete filas como mucho.
 */
final class ModulePrinterResolver
{
    public function __construct(private readonly PrinterRegistry $registro) {}

    public function resolver(string $moduleKey, User $usuario, Company $company): ?Printer
    {
        $asignadaId = $this->modulesMap($company)[$moduleKey] ?? null;

        if ($asignadaId !== null) {
            $asignada = Printer::query()->where('is_active', true)->find($asignadaId);

            if ($asignada !== null) {
                return $asignada;
            }
            // La asignada se borró o se desactivó: se sigue cayendo a la preferencia del usuario, no
            // se detiene aquí con un error — imprimir no debe romperse porque alguien retiró un equipo.
        }

        $predeterminada = $this->registro->predeterminadaDe($usuario, $company->id);

        return $predeterminada?->is_active ? $predeterminada : null;
    }

    /**
     * El mapa completo módulo → id de impresora (o null si no se asignó), para pintar la pantalla.
     *
     * @return array<string, ?int>
     */
    public function modulesMap(Company $company): array
    {
        $guardado = (array) data_get($company->settings, 'printing.modules', []);

        $mapa = [];
        foreach (PrintableModules::keys() as $clave) {
            $valor = $guardado[$clave] ?? null;
            $mapa[$clave] = $valor !== null ? (int) $valor : null;
        }

        return $mapa;
    }

    /** Asigna (o quita, con null) la impresora de un módulo. */
    public function asignar(Company $company, string $moduleKey, ?int $printerId): void
    {
        $settings = $company->settings ?? [];
        $settings['printing']['modules'][$moduleKey] = $printerId;
        $company->update(['settings' => $settings]);
    }
}
