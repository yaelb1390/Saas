<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Dgii;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Habla con los servicios de la DGII [DT]. Única clase de BMIA que conoce sus URLs.
 *
 *   · El ambiente es explícito en CADA llamada y sale del segmento de la URL (testecf/certecf/ecf):
 *     un token de pruebas no puede usarse en producción porque se guarda por empresa Y ambiente.
 *   · Autenticación por semilla firmada → token Bearer [DT pp.8–11], cacheado CIFRADO hasta poco
 *     antes de `expira` (nunca se supone que dura una hora).
 *   · Tiempos cortos: la función de Vercel corta en ~10 s.
 *   · Lo que llega de la DGII se lee sin red, sin entidades y rechazando DOCTYPE (anti-XXE).
 */
final class DgiiClient
{
    public function __construct(
        private readonly CertificateVault $certificates,
        private readonly XmlSigner $signer,
    ) {}

    public function url(Environment $env, string $service): string
    {
        $def = (array) config("ecf.services.{$service}");
        $host = rtrim((string) config('ecf.hosts.'.($def['host'] ?? 'ecf')), '/');

        if ($host === '' || ! isset($def['path'])) {
            throw new RuntimeException("Servicio de la DGII desconocido: {$service}.");
        }

        return $host.'/'.$env->segment().$def['path'];
    }

    /** POST multipart con el campo `xml` [DT]. */
    public function postXml(Company $company, Environment $env, string $service, string $xml, string $fileName): Response
    {
        return $this->conToken($company, $env, fn (PendingRequest $http) => $http
            ->attach('xml', $xml, $fileName, ['Content-Type' => 'text/xml'])
            ->post($this->url($env, $service)));
    }

    /** @param  array<string, string>  $query */
    public function get(Company $company, Environment $env, string $service, array $query): Response
    {
        return $this->conToken($company, $env, fn (PendingRequest $http) => $http->get($this->url($env, $service), $query));
    }

    /**
     * Ejecuta con token; si la DGII responde 401 (token caducado antes de tiempo), lo descarta y
     * reintenta UNA vez con uno nuevo.
     */
    private function conToken(Company $company, Environment $env, callable $llamada): Response
    {
        $respuesta = $llamada($this->http()->withToken($this->token($company, $env)));

        if ($respuesta->status() === 401) {
            Cache::forget($this->claveToken($company, $env));
            $respuesta = $llamada($this->http()->withToken($this->token($company, $env)));
        }

        return $respuesta;
    }

    public function token(Company $company, Environment $env): string
    {
        $guardado = Cache::get($this->claveToken($company, $env));

        if (is_string($guardado)) {
            try {
                $datos = json_decode(Crypt::decryptString($guardado), true, flags: JSON_THROW_ON_ERROR);
                $margen = (int) config('ecf.http.token_refresh_margin_seconds', 120);

                if (CarbonImmutable::parse($datos['expira'])->subSeconds($margen)->isFuture()) {
                    return (string) $datos['token'];
                }
            } catch (Throwable) {
                // Token ilegible: se pide uno nuevo.
            }
        }

        return $this->autenticar($company, $env);
    }

    private function autenticar(Company $company, Environment $env): string
    {
        $semilla = $this->http()->accept('*/*')->get($this->url($env, 'seed'));
        $semilla->throw();

        $doc = $this->xmlSeguro($semilla->body());
        $firmada = $this->signer->sign($doc, $this->certificates->load($company), now(), writeSignatureDate: false);

        $respuesta = $this->http()
            ->attach('xml', $firmada->xml, 'semilla.xml', ['Content-Type' => 'text/xml'])
            ->post($this->url($env, 'validate_seed'));
        $respuesta->throw();

        $token = (string) $respuesta->json('token');
        $expira = (string) $respuesta->json('expira');

        if ($token === '' || $expira === '') {
            throw new RuntimeException('La DGII no devolvió un token válido al validar la semilla.');
        }

        $vence = CarbonImmutable::parse(trim($expira));
        Cache::put(
            $this->claveToken($company, $env),
            Crypt::encryptString(json_encode(['token' => $token, 'expira' => $vence->toIso8601String()], JSON_THROW_ON_ERROR)),
            $vence,
        );

        return $token;
    }

    /** Carga XML recibido de fuera: sin red, sin entidades y sin DOCTYPE (anti-XXE). */
    public function xmlSeguro(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Respuesta XML rechazada: contiene DOCTYPE o entidades.');
        }

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;

        if (! $doc->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Respuesta XML ilegible.');
        }

        return $doc;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('ecf.http.connect_timeout', 3))
            ->timeout((int) config('ecf.http.timeout', 8));
    }

    private function claveToken(Company $company, Environment $env): string
    {
        return "ecf:dgii-token:{$company->id}:{$env->value}";
    }
}
