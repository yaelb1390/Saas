<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SaleController;
use Illuminate\Support\Facades\Route;

/*
 * API REST versionada (v1). Autenticación por token de Sanctum: cada token pertenece a un usuario
 * y, por tanto, a una empresa; SetApiCompany fija ese tenant y todo queda aislado por empresa.
 *
 * Los permisos se aplican con el mismo middleware `can:` que en el panel: un token hereda lo que
 * su usuario puede hacer según su rol. Las rutas exigen además el módulo contratado por la empresa.
 */

Route::prefix('v1')->group(function (): void {
    // Emisión de token (público). El resto exige token válido.
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

        // Inventario
        Route::middleware('module:inventory')->group(function (): void {
            Route::get('/products', [ProductController::class, 'index'])->middleware('can:products.view')->name('api.products.index');
            Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('can:products.view')->name('api.products.show');
            Route::post('/products', [ProductController::class, 'store'])->middleware('can:products.manage')->name('api.products.store');
            Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('can:products.manage')->name('api.products.update');
            Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.manage')->name('api.products.destroy');
        });

        // CRM
        Route::middleware('module:crm')->group(function (): void {
            Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('api.customers.index');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('api.customers.show');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('can:customers.manage')->name('api.customers.store');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.manage')->name('api.customers.update');
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.manage')->name('api.customers.destroy');
        });

        // Ventas
        Route::middleware('module:sales')->group(function (): void {
            Route::get('/sales', [SaleController::class, 'index'])->middleware('can:sales.view')->name('api.sales.index');
            Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware('can:sales.view')->name('api.sales.show');
            Route::post('/sales', [SaleController::class, 'store'])->middleware('can:sales.create')->name('api.sales.store');
        });

        // Facturación (lectura)
        Route::middleware('module:billing')->group(function (): void {
            Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('can:invoices.view')->name('api.invoices.index');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('can:invoices.view')->name('api.invoices.show');
        });
    });
});
