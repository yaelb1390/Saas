<?php

declare(strict_types=1);

/*
 * Los mensajes que ve el cliente están en español.
 *
 * Este fallo es de los peores porque no rompe nada: cuando falta una traducción, Laravel muestra la
 * CLAVE CRUDA —«passwords.sent»— y la pantalla sigue funcionando. No hay error, no hay excepción, no
 * hay nada en los registros. Solo un cliente leyendo un identificador interno.
 *
 * Pasó de verdad: el flujo de recuperación de contraseña se entregó sin `lang/es/passwords.php`, y
 * en producción se salvó por casualidad, porque allí el idioma seguía siendo inglés.
 */

/**
 * Claves del framework que el cliente lee tal cual, con el momento en que las ve.
 *
 * @return array<string, array{0: string, 1: string}>
 */
dataset('claves visibles', [
    'error al iniciar sesión' => ['auth.failed', 'falla el acceso'],
    'demasiados intentos' => ['auth.throttle', 'se reintenta el acceso muchas veces'],
    'enlace enviado' => ['passwords.sent', 'se pide recuperar la contraseña'],
    'contraseña cambiada' => ['passwords.reset', 'se guarda la contraseña nueva'],
    'enlace caducado' => ['passwords.token', 'el enlace ya no vale'],
    'demasiadas peticiones' => ['passwords.throttled', 'se pide el enlace muchas veces'],
    'correo sin cuenta' => ['passwords.user', 'ese correo no está registrado'],
    'paginación anterior' => ['pagination.previous', 'pie de cualquier listado'],
    'paginación siguiente' => ['pagination.next', 'pie de cualquier listado'],
    'campo obligatorio' => ['validation.required', 'se deja un campo vacío'],
    'correo inválido' => ['validation.email', 'el correo está mal escrito'],
    'confirmación' => ['validation.confirmed', 'las contraseñas no coinciden'],
    'valor repetido' => ['validation.unique', 'se repite un valor único'],
]);

it('está traducido al español', function (string $clave, string $cuando): void {
    // Si falta la traducción, `__()` devuelve la propia clave: esa igualdad ES el fallo.
    expect(__($clave, [], 'es'))->not->toBe(
        $clave,
        "Falta la traducción de «{$clave}», que el cliente ve cuando {$cuando}.",
    );
})->with('claves visibles');

it('el idioma configurado es español', function (): void {
    // Con el idioma en inglés lo anterior daría igual: el cliente vería los mensajes del framework
    // en inglés aunque las traducciones existan. Es lo que ocurría en producción, donde no había
    // ninguna variable de idioma y se caía al valor por defecto.
    expect(config('app.locale'))->toBe('es');
});

it('el idioma de reserva NO es español', function (): void {
    // La reserva existe para cuando falta una traducción. Si también fuera español, no habría a
    // dónde caer y se mostraría la clave cruda; con inglés, al menos se lee una frase.
    expect(config('app.fallback_locale'))->not->toBe('es');
});

it('existen los archivos de traducción que el sistema usa', function (): void {
    // Un archivo entero que falte no lo detectaría la comprobación de claves si alguien la recorta.
    foreach (['auth', 'passwords', 'validation', 'pagination'] as $archivo) {
        expect(file_exists(lang_path("es/{$archivo}.php")))
            ->toBeTrue("Falta lang/es/{$archivo}.php");
    }
});
