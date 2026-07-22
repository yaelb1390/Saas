<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Core\Models\Company;
use App\Modules\CRM\Models\Pipeline;

/**
 * Crea el pipeline por defecto (con sus etapas) al dar de alta una empresa.
 */
final class CrmProvisioner
{
    /**
     * Etapas por defecto: [nombre, posición, is_won, is_lost].
     *
     * @var array<int, array{0: string, 1: int, 2: bool, 3: bool}>
     */
    private const DEFAULT_STAGES = [
        ['Nuevo', 1, false, false],
        ['Contactado', 2, false, false],
        ['Propuesta', 3, false, false],
        ['Ganado', 4, true, false],
        ['Perdido', 5, false, true],
    ];

    public function provisionFor(Company $company): Pipeline
    {
        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Ventas',
            'is_default' => true,
        ]);

        foreach (self::DEFAULT_STAGES as [$name, $position, $isWon, $isLost]) {
            $pipeline->stages()->create([
                'company_id' => $company->id,
                'name' => $name,
                'position' => $position,
                'is_won' => $isWon,
                'is_lost' => $isLost,
            ]);
        }

        return $pipeline;
    }
}
