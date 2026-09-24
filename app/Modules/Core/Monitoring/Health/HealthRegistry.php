<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

use App\Modules\Core\Monitoring\Health\Checks\AiCheck;
use App\Modules\Core\Monitoring\Health\Checks\DatabaseCheck;
use App\Modules\Core\Monitoring\Health\Checks\EvolutionCheck;
use App\Modules\Core\Monitoring\Health\Checks\MailCheck;
use App\Modules\Core\Monitoring\Health\Checks\PolarCheck;
use App\Modules\Core\Monitoring\Health\Checks\QueueCheck;
use App\Modules\Core\Monitoring\Health\Checks\RedisCheck;
use Illuminate\Support\Collection;

/** Las sondas que existen, en un solo sitio. */
final class HealthRegistry
{
    /**
     * @param  list<HealthCheck>  $sondas
     */
    public function __construct(
        private readonly array $sondas = [],
    ) {}

    /** La lista de fábrica, resuelta por el contenedor (para que `PolarCheck` reciba su `PolarClient`). */
    public static function porOmision(): self
    {
        return new self([
            app(DatabaseCheck::class),
            app(RedisCheck::class),
            app(QueueCheck::class),
            app(EvolutionCheck::class),
            app(AiCheck::class),
            app(PolarCheck::class),
            app(MailCheck::class),
        ]);
    }

    /**
     * @return Collection<int, HealthCheck>
     */
    public function todas(): Collection
    {
        return collect($this->sondas);
    }

    public function porClave(string $clave): ?HealthCheck
    {
        return $this->todas()->first(fn (HealthCheck $c): bool => $c->key() === $clave);
    }

    /** @return list<string> */
    public function claves(): array
    {
        return $this->todas()->map(fn (HealthCheck $c): string => $c->key())->all();
    }
}
