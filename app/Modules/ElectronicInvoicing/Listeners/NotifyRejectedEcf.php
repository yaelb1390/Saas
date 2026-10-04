<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Listeners;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Events\EcfStatusChanged;
use App\Modules\ElectronicInvoicing\Mail\EcfRejectedMail;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa al dueño por correo cuando la DGII rechaza un e-CF de PRODUCCIÓN.
 *
 * Solo producción: en pruebas y certificación los rechazos son parte de probar y avisar de cada uno
 * sería ruido. Se puede apagar con `ecf.notifications.email_on_rejection`. Un fallo del correo nunca
 * afecta al documento: se reporta y se sigue.
 */
final class NotifyRejectedEcf
{
    public function handle(EcfStatusChanged $e): void
    {
        if ($e->to !== EcfStatus::Rechazado || ! $e->environment->isFiscal() || ! config('ecf.notifications.email_on_rejection', true)) {
            return;
        }

        try {
            $company = Company::query()->find($e->companyId);
            $ecf = ElectronicInvoice::query()->withoutGlobalScopes()->find($e->electronicInvoiceId);
            $owner = $company?->ownerUser();
            $to = $owner?->email ?? $company?->email;

            if ($company === null || $ecf === null || blank($to)) {
                return;
            }

            Mail::to($to)->send(new EcfRejectedMail(
                ownerName: (string) ($owner?->name ?? $company->name),
                companyName: (string) $company->name,
                encf: $ecf->e_ncf,
                typeLabel: $ecf->ecf_type->label(),
                total: number_format((float) $ecf->total, 2),
                reason: (string) ($ecf->last_error ?: 'Sin detalle de la DGII.'),
                documentUrl: route('panel.e-invoicing.documents.show', $ecf->id),
                supportWhatsapp: (string) config('platform.support_whatsapp'),
                supportEmail: (string) config('platform.support_email'),
            ));
        } catch (Throwable $ex) {
            report($ex);
        }
    }
}
