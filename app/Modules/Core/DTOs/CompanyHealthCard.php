<?php

declare(strict_types=1);

namespace App\Modules\Core\DTOs;

use Illuminate\Support\Collection;

/**
 * La ficha de UNA empresa: seis dominios (Sistema, Facturación, WhatsApp, IA, Ventas, Caja), cada
 * uno con su propio estado y su lista de problemas. Reemplaza tener que leer diez campos sueltos de
 * un array —lo que hace `CompanyHealthService::porEmpresa()`, que SIGUE existiendo tal cual para la
 * tabla actual de la pestaña «Empresas»— por una vista agrupada, con un solo «¿esto va bien?» por
 * dominio en vez de ocho banderas sin relación aparente entre sí.
 */
final readonly class CompanyHealthCard
{
    public const HEALTHY = 'healthy';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    /**
     * @param  array<string, array{estado: string, problemas: list<CompanyProblem>}>  $dominios
     */
    public function __construct(
        public int $id,
        public string $nombre,
        public bool $activa,
        public ?string $plan,
        public array $dominios,
    ) {}

    /** El peor de los seis: uno crítico manda sobre cualquier cantidad de avisos. */
    public function estadoGeneral(): string
    {
        $estados = array_column($this->dominios, 'estado');

        return match (true) {
            in_array(self::CRITICAL, $estados, true) => self::CRITICAL,
            in_array(self::WARNING, $estados, true) => self::WARNING,
            default => self::HEALTHY,
        };
    }

    /**
     * Todos los problemas de todos los dominios, en una sola lista —para la ficha expandida, donde
     * no importa de qué dominio viene cada uno, importa la lista completa—.
     *
     * @return Collection<int, CompanyProblem>
     */
    public function problemas(): Collection
    {
        return collect($this->dominios)->flatMap(fn (array $d): array => $d['problemas'])->values();
    }

    public function estadoDe(string $dominio): string
    {
        return $this->dominios[$dominio]['estado'] ?? self::HEALTHY;
    }
}
