<?php

declare(strict_types=1);

use App\Modules\Core\Services\TenantDataPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/*
 * La purga tiene que conocer TODAS las tablas de empresa.
 *
 * El servicio llevaba escrito el aviso de añadir cada tabla nueva a la lista, y aun así seis se
 * quedaron fuera: sus filas sobrevivían a la purga apuntando a datos que ya no existían.
 *
 * Este test convierte ese aviso en una obligación: si alguien crea una tabla con `company_id` y no
 * decide qué hacer con ella, falla aquí y no meses después con datos huérfanos en producción.
 */

uses(RefreshDatabase::class);

/**
 * Tablas que tienen columna `company_id`.
 *
 * Se pregunta por la API de esquema y no por information_schema: los tests corren sobre SQLite y
 * allí esa vista no existe, así que la comprobación se saltaría entera sin avisar.
 *
 * @return Collection<int, string>
 */
function tablasDeEmpresa(): Collection
{
    return collect(Schema::getTableListing())
        ->map(fn (string $tabla): string => str_contains($tabla, '.') ? explode('.', $tabla)[1] : $tabla)
        ->filter(fn (string $tabla): bool => in_array('company_id', Schema::getColumnListing($tabla), true))
        ->values();
}

it('toda tabla con company_id está purgada o conservada a propósito', function (): void {
    $conCompanyId = tablasDeEmpresa();

    $decididas = collect(TenantDataPurger::TABLES)->merge(TenantDataPurger::KEPT);

    $olvidadas = $conCompanyId->diff($decididas)->sort()->values()->all();

    expect($olvidadas)->toBe([], 'Tablas sin decidir: añádelas a TABLES (se borran) o a KEPT (se conservan): '
        .implode(', ', $olvidadas));
});

it('no lista tablas que ya no existen', function (): void {
    // Una tabla renombrada o eliminada dejaría un borrado que falla en silencio... o que revienta
    // la purga entera por una tabla inexistente.
    $existentes = collect(Schema::getTableListing())
        ->map(fn (string $tabla): string => str_contains($tabla, '.') ? explode('.', $tabla)[1] : $tabla);

    $fantasmas = collect(TenantDataPurger::TABLES)->diff($existentes)->values()->all();

    expect($fantasmas)->toBe([]);
});

it('borra las opciones vendidas antes que la línea de venta', function (): void {
    // El orden no es estético: las claves foráneas lo imponen. Si una hija se borrara después de
    // su padre, la purga fallaría entera y la empresa se quedaría a medio limpiar.
    $orden = array_flip(TenantDataPurger::TABLES);

    expect($orden['sale_item_options'])->toBeLessThan($orden['sale_items'])
        ->and($orden['held_orders'])->toBeLessThan($orden['cash_sessions'])
        ->and($orden['product_option_group'])->toBeLessThan($orden['products'])
        ->and($orden['options'])->toBeLessThan($orden['option_groups'])
        ->and($orden['options'])->toBeLessThan($orden['products'])
        ->and($orden['purchase_invoices'])->toBeLessThan($orden['suppliers']);
});
