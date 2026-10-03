<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\ElectronicInvoicing\Models\ElectronicCertificate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Guarda y abre el certificado digital de firma de cada empresa.
 *
 *   · Al subirlo se comprueba de verdad: que se abre con su contraseña, que la clave corresponde al
 *     certificado y que está vigente. Un certificado que no sirve no se guarda.
 *   · El .p12 se CIFRA con la clave de la aplicación antes de tocar el disco, y el disco
 *     (`fiscal_documents`) es privado. La contraseña va cifrada en la base de datos.
 *   · Se abre solo en memoria (`openssl_pkcs12_read` sobre una cadena): la clave privada nunca se
 *     escribe en claro en ningún sitio, ni siquiera en el directorio temporal.
 *   · Reemplazar no borra: el anterior queda desactivado, para saber con cuál se firmó cada documento.
 */
final class CertificateVault
{
    private const DIR = 'ecf/certificados';

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.fiscal_documents', 'local'));
    }

    public function store(
        Company $company,
        string $p12Bytes,
        #[SensitiveParameter] string $password,
        ?int $uploadedBy = null,
    ): ElectronicCertificate {
        $abierto = $this->abrir($p12Bytes, $password);
        $datos = $this->datosPublicos($abierto['cert']);

        $ruta = self::DIR.'/'.$company->id.'/'.Str::ulid()->toBase32().'.p12.enc';

        // throw => true: un fallo de escritura no puede dejar a la empresa apuntando a un archivo
        // que no existe (ya pasó con las fotos de producto en producción).
        self::disk()->put($ruta, Crypt::encryptString(base64_encode($p12Bytes)), ['throw' => true]);

        return DB::transaction(function () use ($company, $ruta, $password, $datos, $uploadedBy): ElectronicCertificate {
            ElectronicCertificate::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'replaced_at' => now()]);

            $certificado = new ElectronicCertificate([
                ...$datos,
                'company_id' => $company->id,
                'path' => $ruta,
                'is_active' => true,
                'uploaded_by' => $uploadedBy,
            ]);
            // La contraseña no está en $fillable: no puede llegar por asignación masiva.
            $certificado->forceFill(['password' => $password])->save();

            return $certificado;
        });
    }

    public function active(Company $company): ?ElectronicCertificate
    {
        return ElectronicCertificate::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    /** Abre el certificado activo en memoria para firmar. */
    public function load(Company $company): LoadedCertificate
    {
        $registro = $this->active($company) ?? throw CertificateException::missing();

        try {
            $bytes = base64_decode(Crypt::decryptString((string) self::disk()->get($registro->path)), true);
        } catch (Throwable) {
            throw CertificateException::storage();
        }

        if ($bytes === false) {
            throw CertificateException::storage();
        }

        $abierto = $this->abrir($bytes, $registro->password);

        return new LoadedCertificate($abierto['pkey'], $abierto['cert'], $registro);
    }

    /** @return array{cert: string, pkey: string} */
    private function abrir(string $bytes, #[SensitiveParameter] string $password): array
    {
        $partes = [];

        if (! @openssl_pkcs12_read($bytes, $partes, $password) || empty($partes['cert']) || empty($partes['pkey'])) {
            throw CertificateException::unreadable();
        }

        if (! openssl_x509_check_private_key($partes['cert'], $partes['pkey'])) {
            throw CertificateException::keyMismatch();
        }

        $info = openssl_x509_parse($partes['cert']);
        $desde = Carbon::createFromTimestamp((int) ($info['validFrom_time_t'] ?? 0));
        $hasta = Carbon::createFromTimestamp((int) ($info['validTo_time_t'] ?? 0));

        if ($hasta->isPast()) {
            throw CertificateException::expired($hasta->format('d/m/Y'));
        }

        if ($desde->isFuture()) {
            throw CertificateException::notYetValid($desde->format('d/m/Y'));
        }

        return ['cert' => $partes['cert'], 'pkey' => $partes['pkey']];
    }

    /** @return array<string, mixed> */
    private function datosPublicos(string $certPem): array
    {
        $info = openssl_x509_parse($certPem) ?: [];

        return [
            'subject' => mb_substr($this->nombre($info['subject'] ?? []), 0, 500),
            'issuer' => mb_substr($this->nombre($info['issuer'] ?? []), 0, 500),
            'serial' => mb_substr((string) ($info['serialNumberHex'] ?? $info['serialNumber'] ?? ''), 0, 100),
            'fingerprint' => (string) openssl_x509_fingerprint($certPem, 'sha256'),
            'valid_from' => Carbon::createFromTimestamp((int) ($info['validFrom_time_t'] ?? 0)),
            'valid_to' => Carbon::createFromTimestamp((int) ($info['validTo_time_t'] ?? 0)),
        ];
    }

    /** @param  array<string, string|array<int, string>>  $partes */
    private function nombre(array $partes): string
    {
        return collect($partes)
            ->map(fn ($v, $k): string => $k.'='.(is_array($v) ? implode(', ', $v) : $v))
            ->implode(', ');
    }
}
