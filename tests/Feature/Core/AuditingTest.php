<?php

declare(strict_types=1);

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

it('registra una auditoría al crear una empresa', function (): void {
    app(CurrentCompany::class)->forget();

    $company = Company::create(['name' => 'Auditada', 'slug' => 'auditada']);

    $audit = Audit::query()
        ->where('auditable_type', Company::class)
        ->where('auditable_id', $company->id)
        ->where('event', 'created')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->getModified())->toHaveKey('name');
});
