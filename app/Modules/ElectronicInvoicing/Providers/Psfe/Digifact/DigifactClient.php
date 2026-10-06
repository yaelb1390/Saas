<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * La conexión con la API de Digifact (https://documentacion.digifact.com/do/api.md, V1.0.4).
 *
 * Token: `POST /login/get_token` con `Username` = `DO.{RNC}.{usuario}` y `Password`; dura 30 días y va
 * en la cabecera `Authorization`. Se guarda CIFRADO en caché por empresa, entorno y credenciales
 * (cambiar la contraseña invalida el anterior) hasta un día antes de que caduque. Ante un 401 se
 * renueva una vez, como hace `Dgii/DgiiClient` con el de la DGII.
 */
final class DigifactClient
{
    /** El entorno de Digifact para un ambiente de BMIA: solo producción va a producción. */
    public function base(Environment $env): string
    {
        $hosts = (array) config('ecf_psfe.digifact.hosts');

        return rtrim((string) ($env->isFiscal() ? $hosts['produccion'] : $hosts['pruebas']), '/');
    }

    /** El RNC del emisor, de sus datos fiscales en BMIA (Digifact lo pide en el usuario y en cada consulta). */
    public function rnc(Company $company): string
    {
        return preg_replace('/\D/', '', (string) ElectronicInvoicingSettings::paraEmpresa($company)->tax_id) ?? '';
    }

    /** @param  array<string, string>  $credentials */
    public function username(Company $company, #[SensitiveParameter] array $credentials): string
    {
        return 'DO.'.$this->rnc($company).'.'.($credentials['usuario'] ?? '');
    }

    /**
     * Hace una petición autenticada. `$llamada` recibe la petición preparada y la URL base.
     *
     * @param  array<string, string>  $credentials
     * @param  callable(PendingRequest, string): Response  $llamada
     *
     * @throws DigifactAuthException si Digifact no acepta el usuario o la contraseña
     * @throws ConnectionException si no se pudo hablar con Digifact
     */
    public function request(Company $company, #[SensitiveParameter] array $credentials, Environment $env, callable $llamada): Response
    {
        $respuesta = $llamada($this->http()->withHeaders(['Authorization' => $this->token($company, $credentials, $env)]), $this->base($env));

        if ($respuesta->status() === 401) {
            Cache::forget($this->claveToken($company, $credentials, $env));
            $respuesta = $llamada($this->http()->withHeaders(['Authorization' => $this->token($company, $credentials, $env)]), $this->base($env));
        }

        return $respuesta;
    }

    /**
     * @param  array<string, string>  $credentials
     *
     * @throws DigifactAuthException
     * @throws ConnectionException
     */
    public function token(Company $company, #[SensitiveParameter] array $credentials, Environment $env): string
    {
        $clave = $this->claveToken($company, $credentials, $env);
        $guardado = Cache::get($clave);

        if (is_string($guardado)) {
            try {
                return Crypt::decryptString($guardado);
            } catch (Throwable) {
                Cache::forget($clave);
            }
        }

        $r = $this->http()->post($this->base($env).'/login/get_token', [
            'Username' => $this->username($company, $credentials),
            'Password' => $credentials['clave'] ?? '',
        ]);

        $token = (string) ($r->json('Token') ?? $r->json('token') ?? '');

        if ($r->status() === 401 || $r->status() === 400 || ($r->successful() && $token === '')) {
            throw new DigifactAuthException('Digifact rechazó el usuario o la contraseña.');
        }

        if (! $r->successful()) {
            throw new ConnectionException("Digifact respondió con un error al pedir el acceso (HTTP {$r->status()}).");
        }

        $vence = $this->vencimiento($r->json('expira_en'));
        Cache::put($clave, Crypt::encryptString($token), $vence->subHours((int) config('ecf_psfe.digifact.token_refresh_margin_hours', 24)));

        return $token;
    }

    /**
     * Si un fallo de conexión ocurrió ANTES de que el documento saliera (no se pudo resolver el nombre o
     * conectar: cURL 6 y 7). Un tiempo agotado (cURL 28) pudo ocurrir con el documento ya entregado.
     */
    public static function neverReached(ConnectionException $e): bool
    {
        return (bool) preg_match('/cURL error (6|7):/', $e->getMessage());
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('ecf.http.connect_timeout', 3))
            ->timeout((int) config('ecf.http.timeout', 8));
    }

    /** `expira_en` según la documentación; si no se entiende, se asume un día (se pedirá otro antes). */
    private function vencimiento(mixed $expira): CarbonImmutable
    {
        try {
            $fecha = is_string($expira) && $expira !== '' ? CarbonImmutable::parse($expira) : null;
        } catch (Throwable) {
            $fecha = null;
        }

        return $fecha !== null && $fecha->isFuture() ? $fecha : CarbonImmutable::now()->addDays(2);
    }

    /** @param  array<string, string>  $credentials */
    private function claveToken(Company $company, #[SensitiveParameter] array $credentials, Environment $env): string
    {
        $entorno = $env->isFiscal() ? 'produccion' : 'pruebas';

        return "ecf:digifact-token:{$company->id}:{$entorno}:".hash('sha256', ($credentials['usuario'] ?? '').'|'.($credentials['clave'] ?? ''));
    }
}
