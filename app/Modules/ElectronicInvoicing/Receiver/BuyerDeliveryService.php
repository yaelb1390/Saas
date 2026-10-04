<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Receiver;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceAuditLog;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\ProviderResolver;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;
use App\Modules\ElectronicInvoicing\Storage\FiscalDocumentStore;
use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use DOMXPath;
use Throwable;

/**
 * [DT p.12] «Haber recibido un estado de validación satisfactorio habilita al emisor al envío del
 * e-CF al receptor y, en caso de que este no sea electrónico, la entrega de la representación impresa.»
 *
 * Un e-CF ACEPTADO con comprador identificado queda «pendiente» de entrega; el procesador lo busca en
 * el directorio de la DGII y, si es receptor electrónico, le envía el XML firmado a su recepción y
 * guarda el acuse (ARECF) que devuelve. Si no lo es, no hay nada más que enviar (va la RI).
 */
final class BuyerDeliveryService
{
    public const PENDIENTE = 'pendiente';

    public const ENVIADO = 'enviado';

    public const NO_ELECTRONICO = 'no_electronico';

    public const NO_APLICA = 'no_aplica';

    public const ERROR = 'error';

    /** Intentos antes de dejarlo en error para revisión manual. */
    private const MAX_INTENTOS = 5;

    public function __construct(
        private readonly ProviderResolver $providers,
        private readonly PeerClient $peers,
        private readonly FiscalDocumentStore $files,
    ) {}

    public function deliver(ElectronicInvoice $ecf): ElectronicInvoice
    {
        if (($ecf->getAttributes()['buyer_delivery_status'] ?? null) !== self::PENDIENTE) {
            return $ecf;
        }

        $company = Company::query()->findOrFail($ecf->company_id);
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);
        $proveedor = $this->providers->for($settings);
        $directorio = $proveedor->findReceiver($company, $ecf->environment, (string) $ecf->buyer_tax_id);

        if ($directorio->status === ReceiverLookup::NOT_ELECTRONIC || $directorio->status === ReceiverLookup::UNSUPPORTED) {
            return $this->cerrar($ecf, $directorio->status === ReceiverLookup::NOT_ELECTRONIC ? self::NO_ELECTRONICO : self::NO_APLICA, $directorio->error);
        }

        if ($directorio->status === ReceiverLookup::ERROR) {
            return $this->fallo($ecf, 'Directorio: '.$directorio->error);
        }

        try {
            $xml = $this->files->get($ecf->file('firmado') ?? throw new \RuntimeException('Falta el XML firmado.'));
            $nombre = preg_replace('/\D/', '', (string) $settings->tax_id).$ecf->e_ncf.'.xml';
            $r = $this->peers->post(PeerClient::endpoint((string) $directorio->receptionUrl, PeerClient::RECEPCION), $xml, $nombre, $this->peers->token($company, $directorio->authUrl));
        } catch (Throwable $e) {
            return $this->fallo($ecf, $e->getMessage());
        }

        if (! $r->successful()) {
            return $this->fallo($ecf, "HTTP {$r->status()} ".mb_substr($r->body(), 0, 200));
        }

        // El receptor responde con su ARECF firmado: se guarda tal cual y se lee su estado.
        [$estado, $motivo] = [null, null];
        try {
            $this->files->put($ecf, 'arecf_comprador', $r->body());
            $x = new DOMXPath(SafeXml::load($r->body()));
            $estado = ($e = trim((string) $x->evaluate("string(//*[local-name()='Estado'][1])"))) === '' ? null : (int) $e;
            $motivo = ($m = trim((string) $x->evaluate("string(//*[local-name()='CodigoMotivoNoRecibido'][1])"))) === '' ? null : (int) $m;
        } catch (Throwable) {
            // Respuesta no legible: el envío llegó (HTTP 2xx), pero sin acuse que leer.
        }

        $ecf->forceFill(['buyer_receipt_status' => $estado, 'buyer_receipt_reason' => $motivo])->save();

        return $this->cerrar($ecf, self::ENVIADO, null);
    }

    private function cerrar(ElectronicInvoice $ecf, string $estado, ?string $detalle): ElectronicInvoice
    {
        $ecf->forceFill([
            'buyer_delivery_status' => $estado,
            'buyer_delivered_at' => $estado === self::ENVIADO ? now() : null,
            'buyer_delivery_error' => $detalle !== null ? mb_substr($detalle, 0, 500) : null,
        ])->save();

        $this->log($ecf, match ($estado) {
            self::ENVIADO => 'e-CF enviado al comprador',
            self::NO_ELECTRONICO => 'El comprador no es receptor electrónico (se entrega la representación impresa)',
            default => 'Envío al comprador no aplica con este proveedor',
        });

        return $ecf->refresh();
    }

    private function fallo(ElectronicInvoice $ecf, string $detalle): ElectronicInvoice
    {
        $intentos = (int) ($ecf->getAttributes()['buyer_delivery_attempts'] ?? 0) + 1;
        $ecf->forceFill([
            'buyer_delivery_attempts' => $intentos,
            'buyer_delivery_status' => $intentos >= self::MAX_INTENTOS ? self::ERROR : self::PENDIENTE,
            'buyer_delivery_error' => mb_substr($detalle, 0, 500),
        ])->save();

        if ($intentos >= self::MAX_INTENTOS) {
            $this->log($ecf, 'No se pudo enviar el e-CF al comprador');
        }

        return $ecf->refresh();
    }

    private function log(ElectronicInvoice $ecf, string $accion): void
    {
        ElectronicInvoiceAuditLog::create([
            'company_id' => $ecf->company_id,
            'electronic_invoice_id' => $ecf->id,
            'e_ncf' => $ecf->e_ncf,
            'action' => $accion,
            'details' => array_filter(['comprador' => $ecf->buyer_tax_id, 'detalle' => $ecf->buyer_delivery_error]),
        ]);
    }
}
