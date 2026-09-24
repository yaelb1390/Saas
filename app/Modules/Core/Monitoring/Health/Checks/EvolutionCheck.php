<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Support\SecretRedactor;
use App\Modules\WhatsApp\Models\WaBotSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ¿Responde Evolution API? Por la MISMA ruta que ya usa `EvolutionGateway::status()` en producción
 * —`GET /instance/connectionState/{instancia}`—, contra una empresa real que tenga la línea por QR
 * encendida. Es a propósito y no una ruta inventada para el monitoreo: la que se comprueba aquí es
 * exactamente la que se usa para atender de verdad, así que un «bien» aquí significa «el bot
 * funcionaría ahora mismo», no «el servidor contesta a algo».
 *
 * SIN NINGUNA EMPRESA con la línea por QR encendida, no hay instancia contra la que preguntar. No se
 * inventa una comprobación contra la raíz del servidor: esa ruta no se ha podido verificar contra una
 * instancia real en esta fase, y una sonda que comprueba algo distinto de lo que dice contradice el
 * motivo por el que existe esta fase. Queda «no configurado» hasta que haya una línea real que mirar.
 */
final class EvolutionCheck implements HealthCheck
{
    public function key(): string
    {
        return 'evolution';
    }

    public function label(): string
    {
        return 'WhatsApp (Evolution)';
    }

    public function run(): HealthResult
    {
        $base = (string) config('evolution.base_url');

        if ($base === '') {
            return HealthResult::sinConfigurar();
        }

        $instancia = $this->instanciaDeReferencia();

        if ($instancia === null) {
            return HealthResult::sinConfigurar('Ninguna empresa tiene la línea por QR activa: nada que comprobar');
        }

        $inicio = microtime(true);

        try {
            $respuesta = Http::withHeaders(['apikey' => (string) config('evolution.api_key')])
                ->acceptJson()
                ->timeout(4)
                ->get(rtrim($base, '/').'/instance/connectionState/'.$instancia);
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }

        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        // 401/403: la clave de API está mal. 5xx o sin respuesta: el servidor está caído. Los dos son
        // «no funciona», y da igual el motivo para quien espera que el bot conteste.
        if ($respuesta->status() === 401 || $respuesta->status() === 403) {
            return HealthResult::caido('La clave de API no es válida (401/403)', $latencia);
        }

        if ($respuesta->serverError()) {
            return HealthResult::caido("El servidor respondió {$respuesta->status()}", $latencia);
        }

        // 404 significa que SE ALCANZÓ el servidor y contestó que esa instancia no existe: el
        // servicio está vivo, solo que esa línea en concreto no está dada de alta.
        if ($respuesta->status() === 404) {
            return HealthResult::sano($latencia, 'Alcanzable (la instancia de referencia no existe)');
        }

        if (! $respuesta->successful()) {
            return HealthResult::caido("Respuesta inesperada: {$respuesta->status()}", $latencia);
        }

        return HealthResult::sano($latencia);
    }

    /** El slug de una empresa con la línea por QR activa, para tener una instancia real que mirar. */
    private function instanciaDeReferencia(): ?string
    {
        $companyId = WaBotSetting::query()->withoutGlobalScopes()
            ->where('provider', WaBotSetting::POR_QR)
            ->where('is_active', true)
            ->value('company_id');

        if ($companyId === null) {
            return null;
        }

        return Company::query()->whereKey($companyId)->value('slug');
    }
}
