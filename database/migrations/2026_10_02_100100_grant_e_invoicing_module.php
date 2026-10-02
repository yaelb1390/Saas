<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Da el módulo de Facturación Electrónica a los planes y empresas que ya tienen Facturación.
 *
 * Mismo motivo y misma regla que `2026_08_23_140300_grant_quotes_module`: un módulo nuevo no llega
 * solo a quien ya está dado de alta, y solo se concede, nunca se retira. `modules = NULL` significa
 * «todos, también los futuros»: esas filas ya están al día.
 *
 * Se concede a quien tiene `billing`: el e-CF es la forma electrónica de los comprobantes que ese
 * módulo ya emite, y la Ley 32-23 lo hace obligatorio para quien factura.
 */
return new class extends Migration
{
    private const NUEVO = 'e_invoicing';

    private const REQUIERE = 'billing';

    public function up(): void
    {
        $this->conceder('plans');
        $this->conceder('companies');
    }

    /** Solo se deshace en los PLANES; lo concedido a cada empresa se queda (ver la migración de Cotizaciones). */
    public function down(): void
    {
        foreach ($this->filasConLista('plans') as $fila) {
            $modulos = $this->lista($fila->modules);

            if (! in_array(self::NUEVO, $modulos, true)) {
                continue;
            }

            DB::table('plans')->where('id', $fila->id)->update([
                'modules' => json_encode(array_values(array_diff($modulos, [self::NUEVO]))),
            ]);
        }
    }

    private function conceder(string $tabla): void
    {
        foreach ($this->filasConLista($tabla) as $fila) {
            $modulos = $this->lista($fila->modules);

            if (! in_array(self::REQUIERE, $modulos, true) || in_array(self::NUEVO, $modulos, true)) {
                continue;
            }

            $modulos[] = self::NUEVO;

            DB::table($tabla)->where('id', $fila->id)->update([
                'modules' => json_encode(array_values($modulos)),
            ]);
        }
    }

    /** @return Collection<int, object> */
    private function filasConLista(string $tabla): Collection
    {
        return DB::table($tabla)->whereNotNull('modules')->get(['id', 'modules']);
    }

    /** @return array<int, string> */
    private function lista(mixed $valor): array
    {
        if (is_array($valor)) {
            return $valor;
        }

        $decodificado = json_decode((string) $valor, true);

        return is_array($decodificado) ? $decodificado : [];
    }
};
