<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Core\Models\Company;
use App\Modules\SocialCommerce\Models\ContactIdentity;
use Illuminate\Support\Carbon;

/**
 * Encuentra o crea la identidad de Instagram de quien escribió, sin tocar `Customer` (ver
 * `social_commerce_contact_identities`, auditoría sección 6).
 */
final class ContactIdentityResolver
{
    public function resolve(Company $company, string $externalId, ?string $username, ?string $displayName): ContactIdentity
    {
        $identidad = ContactIdentity::query()
            ->where('channel', 'instagram')
            ->where('external_id', $externalId)
            ->first();

        $ahora = Carbon::now();

        if ($identidad === null) {
            return ContactIdentity::create([
                'company_id' => $company->id,
                'channel' => 'instagram',
                'external_id' => $externalId,
                'external_username' => $username,
                'display_name' => $displayName,
                'first_seen_at' => $ahora,
                'last_seen_at' => $ahora,
            ]);
        }

        $identidad->last_seen_at = $ahora;

        // Se refrescan si llegó un valor nuevo, nunca se borran con uno vacío: el @usuario puede
        // cambiar, pero un aviso sin nombre no significa que la persona dejó de tener uno.
        if (filled($username)) {
            $identidad->external_username = $username;
        }

        if (filled($displayName)) {
            $identidad->display_name = $displayName;
        }

        $identidad->save();

        return $identidad;
    }
}
