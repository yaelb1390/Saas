<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Enums\SocialPlatform;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Services\ZernioClient;
use App\Modules\SocialCommerce\Http\Requests\StoreSettingsRequest;
use App\Modules\SocialCommerce\Models\Settings;
use App\Modules\SocialCommerce\Services\WebhookRegistrar;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Conectar la cuenta de Instagram/Facebook y configurar el número de WhatsApp de Social Commerce.
 *
 * Usa la MISMA clave de Zernio que el módulo `Social` (`companies.social_api_key`, arquitectura
 * sección 2), pero con su propio permiso: una empresa puede tener Social Commerce sin tener
 * contratado `social`, y esta pantalla es la que le permite conectar la cuenta igual.
 */
final class SettingsController extends Controller
{
    public function index(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $cliente = new ZernioClient($company);
        $cuentas = [];
        $aviso = null;

        if ($cliente->isConfigured()) {
            try {
                $cuentas = array_values(array_filter(
                    $cliente->accounts(),
                    static fn (array $c): bool => in_array($c['platform'], ['instagram', 'facebook'], true),
                ));
            } catch (SocialException $e) {
                $aviso = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $aviso = 'No se pudo hablar con el servicio de redes. Vuelve a intentarlo en un momento.';
            }
        }

        return view('panel.social-commerce.settings', [
            'configurado' => $cliente->isConfigured(),
            'ajustes' => Settings::paraEmpresa((int) $company->id),
            'cuentas' => $cuentas,
            'aviso' => $aviso,
            'plataformas' => [SocialPlatform::Instagram, SocialPlatform::Facebook],
        ]);
    }

    public function saveKey(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $datos = $request->validate([
            'api_key' => ['nullable', 'string', 'regex:/^sk_[0-9a-f]{64}$/'],
        ], [
            'api_key.regex' => 'Esa no parece una clave de Zernio: empiezan por «sk_» y siguen 64 caracteres.',
        ]);

        $company->update(['social_api_key' => $datos['api_key'] ?: null]);

        return back()->with('panel_ok', filled($datos['api_key'] ?? null)
            ? 'Clave guardada. Ya puedes conectar tu cuenta de Instagram.'
            : 'Clave borrada.');
    }

    public function connect(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $request->validate(['platform' => ['required', Rule::in(['instagram', 'facebook'])]]);

        try {
            $url = (new ZernioClient($company))->connectUrl(SocialPlatform::from((string) $request->input('platform')));
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function update(StoreSettingsRequest $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $ajustes = Settings::paraEmpresa((int) $company->id);
        $ajustes->fill([
            'whatsapp_number' => $request->validated('whatsapp_number') ?: null,
        ]);
        $ajustes->is_active = $request->boolean('is_active');
        $ajustes->save();

        try {
            (new WebhookRegistrar($company))->sincronizar();
        } catch (SocialException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Ajustes guardados.');
    }
}
