<?php

declare(strict_types=1);

use App\Modules\Core\Mail\TrialWelcomeMail;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    // Plan recortado a propósito: así el correo tiene que listar los módulos DEL PLAN elegido.
    $this->plan = Plan::create([
        'name' => 'Básico', 'slug' => 'basico', 'price' => '750',
        'billing_cycle' => 'monthly', 'trial_days' => 0,
        'modules' => ['pos', 'inventory'], 'is_active' => true,
    ]);
    Mail::fake();
});

it('envía el correo de bienvenida al dueño al registrarse', function (): void {
    $this->post('/registro', [
        'company_name' => 'Colmado La Bendición',
        'owner_name' => 'Yael',
        'owner_email' => 'yael@colmado.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'plan_id' => $this->plan->id,
    ])->assertRedirect(route('dashboard'));

    Mail::assertQueued(TrialWelcomeMail::class, fn (TrialWelcomeMail $mail): bool => $mail->hasTo('yael@colmado.test')
        && $mail->companyName === 'Colmado La Bendición'
        // Los módulos del PLAN elegido, no una selección suelta.
        && $mail->moduleLabels === ['Punto de Venta', 'Inventario']);
});

it('el correo renderiza el negocio y el botón al panel', function (): void {
    $this->post('/registro', [
        'company_name' => 'Mi Empresa SRL',
        'owner_name' => 'Ana',
        'owner_email' => 'ana@empresa.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'plan_id' => $this->plan->id,
    ]);

    Mail::assertQueued(TrialWelcomeMail::class, function (TrialWelcomeMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'Mi Empresa SRL') && str_contains($html, 'Entrar a mi panel');
    });
});

it('no envía correo si el registro es inválido (sin plan)', function (): void {
    $this->post('/registro', [
        'company_name' => 'X', 'owner_name' => 'X', 'owner_email' => 'x@y.test',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!',
    ])->assertSessionHasErrors('plan_id');

    Mail::assertNothingQueued();
});
