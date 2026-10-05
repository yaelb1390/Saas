<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

use App\Modules\ElectronicInvoicing\Domain\Environment;

/**
 * Los conectores de `config/ecf_psfe.php`. Una entrada mal escrita (clase que no existe o que no es
 * un conector) se ignora en vez de tumbar la pantalla de configuración.
 */
final class PsfeCatalog
{
    /** @return array<string, PsfeDriver> */
    public function all(): array
    {
        $conectores = [];

        foreach ((array) config('ecf_psfe.drivers', []) as $slug => $clase) {
            if (is_string($clase) && class_exists($clase) && is_subclass_of($clase, PsfeDriver::class)) {
                $conectores[(string) $slug] = app($clase);
            }
        }

        return $conectores;
    }

    /** @return array<string, PsfeDriver> los que se pueden usar en ese ambiente */
    public function availableIn(Environment $env): array
    {
        return array_filter($this->all(), fn (PsfeDriver $d): bool => $d->availableIn($env));
    }

    public function find(?string $slug): ?PsfeDriver
    {
        return $slug === null ? null : ($this->all()[$slug] ?? null);
    }
}
