<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Receiver;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Habla con los servicios de OTRO contribuyente (su recepción, su aprobación comercial y, si la
 * declara, su autenticación) con el estándar común [DTEE «Creación de Servicios»]: los recursos son
 * iguales para todos y solo cambia el host que da el directorio de la DGII.
 *
 *   · Autenticación: GET {auth}/fe/autenticacion/api/semilla → firmar con el certificado de la empresa
 *     → POST {auth}/fe/autenticacion/api/validacioncertificado (multipart `xml`) → token Bearer.
 *     El token se guarda cifrado por empresa y host hasta poco antes de `expira`.
 *   · Envío: POST multipart con el campo `xml` y el nombre oficial del archivo.
 */
final class PeerClient
{
    public const RECEPCION = '/fe/recepcion/api/ecf';

    public const APROBACION = '/fe/aprobacioncomercial/api/ecf';

    private const SEMILLA = '/fe/autenticacion/api/semilla';

    private const VALIDACION = '/fe/autenticacion/api/validacioncertificado';

    public function __construct(
        private readonly CertificateVault $certificates,
        private readonly XmlSigner $signer,
    ) {}

    /**
     * El directorio da el «host del servicio»; el recurso estándar se añade si no viene ya. Es una
     * interpretación del DTEE (el directorio de ejemplo no muestra si la URL incluye el recurso).
     */
    public static function endpoint(string $base, string $recurso): string
    {
        $base = rtrim(trim($base), '/');

        return str_ends_with(strtolower($base), strtolower($recurso)) ? $base : $base.$recurso;
    }

    public function token(Company $company, ?string $authBase): ?string
    {
        if ($authBase === null || trim($authBase) === '') {
            return null;
        }

        $clave = 'ecf:peer-token:'.$company->id.':'.hash('sha256', strtolower($authBase));
        $guardado = Cache::get($clave);

        if (is_string($guardado)) {
            try {
                return Crypt::decryptString($guardado);
            } catch (Throwable) {
                // Ilegible: se pide otro.
            }
        }

        $raiz = rtrim(preg_replace('#/fe/autenticacion/api/.*$#i', '', $authBase) ?? $authBase, '/');
        $semilla = $this->http()->accept('*/*')->get($raiz.self::SEMILLA)->throw();
        $firmada = $this->signer->sign(SafeXml::load($semilla->body()), $this->certificates->load($company), now(), writeSignatureDate: false)->xml;

        $r = $this->http()->attach('xml', $firmada, 'semilla.xml', ['Content-Type' => 'text/xml'])->post($raiz.self::VALIDACION)->throw();
        $token = (string) $r->json('token');

        if ($token === '') {
            throw new RuntimeException('El receptor no devolvió un token.');
        }

        $expira = filled($r->json('expira')) ? CarbonImmutable::parse((string) $r->json('expira'))->subMinutes(2) : now()->addMinutes(30);
        Cache::put($clave, Crypt::encryptString($token), $expira);

        return $token;
    }

    public function post(string $url, string $xml, string $fileName, ?string $token): Response
    {
        $http = $this->http()->accept('*/*');

        if ($token !== null) {
            $http = $http->withToken($token);
        }

        return $http->attach('xml', $xml, $fileName, ['Content-Type' => 'text/xml'])->post($url);
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout((int) config('ecf.http.connect_timeout', 3))->timeout((int) config('ecf.http.timeout', 8));
    }
}
