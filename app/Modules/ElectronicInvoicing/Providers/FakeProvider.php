<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\Contracts\ElectronicInvoiceProvider;
use Illuminate\Support\Str;

/**
 * Proveedor de prueba: NO envía nada a ningún sitio. Es el que tiene toda empresa recién configurada,
 * para preparar y probar el circuito completo (generar, firmar, «enviar», consultar) sin riesgo.
 *
 * Su respuesta se puede elegir en config (`ecf.fake.*`) para probar cada camino: recibido, aceptado,
 * rechazado con o sin reutilización de número, fallo pasajero.
 */
final class FakeProvider implements ElectronicInvoiceProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(Company $company): bool
    {
        return true;
    }

    public function send(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->enProduccion($env) ?? $this->respuesta((string) config('ecf.fake.send', 'received'), 'FAKE-'.Str::ulid()->toBase32());
    }

    public function sendSummary(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->enProduccion($env) ?? $this->respuesta((string) config('ecf.fake.summary', 'accepted'), null);
    }

    public function queryResult(Company $company, Environment $env, string $trackId): ProviderResult
    {
        return $this->enProduccion($env) ?? $this->respuesta((string) config('ecf.fake.query', 'accepted'), $trackId);
    }

    /**
     * En producción un «aceptado» simulado sería un documento fiscal que la DGII nunca recibió: el
     * proveedor de prueba se niega y el documento queda en error, a la vista.
     */
    private function enProduccion(Environment $env): ?ProviderResult
    {
        return $env->isFiscal()
            ? new ProviderResult(ProviderOutcome::NotConfigured, error: 'El proveedor de prueba no puede usarse en producción: elija un proveedor real.')
            : null;
    }

    private function respuesta(string $resultado, ?string $trackId): ProviderResult
    {
        $outcome = ProviderOutcome::from($resultado);

        return new ProviderResult(
            $outcome,
            trackId: $trackId,
            status: 'Proveedor de prueba: '.$outcome->value,
            messages: [['codigo' => null, 'valor' => 'Respuesta simulada: este documento no se envió a la DGII.']],
            sequenceUsed: $outcome === ProviderOutcome::Rejected ? (bool) config('ecf.fake.sequence_used', true) : null,
            raw: '{"simulado":true}',
            error: $outcome === ProviderOutcome::TransientError ? 'Fallo simulado de comunicación.' : null,
        );
    }
}
