<?php

use App\Modules\Core\Http\Middleware\EnsureModuleActive;
use App\Modules\Core\Http\Middleware\EnsureSubscriptionActive;
use App\Modules\Core\Http\Middleware\SetApiCompany;
use App\Modules\Core\Http\Middleware\SetCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fija el tenant activo tras autenticar en las peticiones web.
        $middleware->web(append: [
            SetCurrentCompany::class,
        ]);

        // La API es stateless: el tenant se resuelve desde el token (empresa del usuario).
        $middleware->api(append: [
            SetApiCompany::class,
        ]);

        $middleware->alias([
            'company' => SetCurrentCompany::class,
            'module' => EnsureModuleActive::class,
            'subscription' => EnsureSubscriptionActive::class,
        ]);

        // Detrás del túnel de Cloudflare (cloudflared → nginx-proxy-manager), la app recibe la
        // petición por HTTP interno pero el usuario está en HTTPS. Sin confiar en el proxy, Laravel
        // generaría URLs y assets con «http://» y el navegador los bloquearía por contenido mixto
        // —y la cámara, que exige contexto seguro, no cargaría—. Al confiar en las cabeceras
        // «X-Forwarded-Proto/Host», Laravel emite «https://» con el hostname público real.
        //
        // Confiar en «*» es seguro aquí: bmos_web solo es alcanzable por la red interna y el proxy,
        // nunca directo desde internet. En acceso local (sin esas cabeceras) no cambia nada.
        $middleware->trustProxies(at: '*');

        // Deben ejecutarse ANTES de SubstituteBindings para que el route model binding resuelva
        // los registros ya aislados por la empresa activa (evita fugas entre empresas por id).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentCompany::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetApiCompany::class,
        );

        // Los webhooks entrantes no llevan token CSRF (se protegen por secreto).
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error 419 «página caducada»: el token CSRF ya no coincide porque la pestaña estuvo abierta
        // más tiempo que la vida de la sesión (o esta se reinició). El framework convierte la
        // TokenMismatchException en HttpException(419) antes de los callbacks, así que enganchamos por
        // estado. En vez de dejar al usuario en una pantalla muerta, lo devolvemos con un aviso claro.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null; // otros errores HTTP siguen su manejo por defecto
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.'], 419);
            }

            // Si la sesión sigue viva (solo el token del formulario quedó viejo), volvemos a la misma
            // página conservando lo tecleado —salvo credenciales— para reenviar con un token fresco.
            if (auth()->check()) {
                return redirect(url()->previous())
                    ->withInput($request->except(['_token', 'password', 'password_confirmation']))
                    ->with('panel_error', 'La página caducó por seguridad. Revisa los datos y vuelve a enviarlos.');
            }

            // La sesión ya expiró por completo: al login con un mensaje comprensible.
            return redirect()->route('login')
                ->withErrors(['email' => 'Tu sesión expiró por inactividad. Inicia sesión de nuevo.']);
        });
    })->create();
