<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Da el módulo de Cotizaciones a los planes y a las empresas que ya existen.
 *
 * Un módulo nuevo no llega solo a quien ya está dado de alta: ni el plan lo enumera ni la lista de
 * la empresa lo tiene, y el middleware mira la de la EMPRESA. Sin esta migración, el menú no
 * enseñaría la entrada y entrar a mano daría un 403 sin que nadie hubiera cambiado nada.
 *
 * Se concede a QUIEN YA TENGA VENTAS. Cotizar es ofrecer un precio para cobrarlo después: a quien no
 * tiene el módulo de ventas contratado no le sirve de nada, porque el botón de cobrar no llevaría a
 * ninguna parte.
 *
 * REGLA, la misma de siempre: solo se concede, nunca se retira. Quitarle a alguien un módulo que ya
 * está usando le rompe una pantalla sin que haya cambiado su contrato.
 *
 * `modules = NULL` significa «todos, también los futuros»: esas filas ya están al día por definición.
 */
return new class extends Migration
{
    private const NUEVO = 'quotes';

    /** Sin ventas, cotizar no lleva a ninguna parte. */
    private const REQUIERE = 'sales';

    public function up(): void
    {
        $this->conceder('plans');
        $this->conceder('companies');
    }

    /**
     * La marcha atrás solo toca los PLANES.
     *
     * Lo concedido a cada empresa no se deshace: no sabemos qué tenía antes, y adivinarlo mal
     * dejaría a un cliente sin una pantalla que ya está usando.
     */
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
