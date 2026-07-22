<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al crear una empresa. Punto de enganche para automatizaciones (n8n),
 * aprovisionamiento inicial y auditoría.
 */
final class CompanyCreated
{
    use Dispatchable;

    public function __construct(public readonly Company $company) {}
}
