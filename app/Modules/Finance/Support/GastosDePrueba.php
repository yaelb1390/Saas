<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\Enums\ExpenseGroup;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;

/**
 * Gastos de mentira para poder mirar la pantalla con algo dentro.
 *
 * ================================================================================================
 * POR QUÉ LLEVAN PREFIJO Y NO HAY UN «BORRAR TODO».
 *
 * Un botón que borra todos los gastos de una empresa es lo más peligroso que se puede poner en esta
 * pantalla: un clic de más y el negocio pierde su historial de en qué se le fue el dinero, que es
 * justo lo que este módulo existe para conservar —hasta el punto de que anular un gasto es un
 * borrado LÓGICO, para no perderlo—.
 *
 * Así que estos nacen marcados: el código empieza por `DEMO-` en vez de `GAS-`, y el borrado solo
 * puede tocar esos. No es posible pedirle que se lleve por delante un gasto de verdad, ni por error
 * ni a propósito.
 * ================================================================================================
 *
 * NO PASAN POR `ExpenseService`, y es deliberado. Ese servicio apunta el gasto en la contabilidad y,
 * si la cuenta es de efectivo y hay un turno abierto, lo descuenta del arqueo. Cincuenta gastos de
 * mentira descuadrarían una caja de verdad. Aquí se escribe la fila y nada más.
 */
final class GastosDePrueba
{
    /** Lo que distingue un gasto de mentira de uno de verdad. */
    public const PREFIJO = 'DEMO-';

    /** Gastos verosímiles de una cafetería, con su categoría y su horquilla de importe. */
    private const CATALOGO = [
        ['Café en grano', ExpenseGroup::Alimentos, 1800, 6500, 'Distribuidora El Cafetal'],
        ['Leche', ExpenseGroup::Alimentos, 900, 2800, 'Lácteos Rica'],
        ['Azúcar', ExpenseGroup::Alimentos, 400, 1200, 'Distribuidora El Cafetal'],
        ['Pan y repostería', ExpenseGroup::Alimentos, 1200, 4000, 'Panadería La Esquina'],
        ['Refrescos', ExpenseGroup::Bebidas, 800, 3500, 'Bebidas del Caribe'],
        ['Agua embotellada', ExpenseGroup::Bebidas, 350, 1400, 'Bebidas del Caribe'],
        ['Vasos y servilletas', ExpenseGroup::Insumos, 600, 2200, 'Suplidora Nacional'],
        ['Detergente', ExpenseGroup::Limpieza, 300, 1100, 'Suplidora Nacional'],
        ['Luz', ExpenseGroup::Servicios, 3500, 9000, 'EdeEste'],
        ['Internet', ExpenseGroup::Servicios, 2500, 2500, 'Claro'],
        ['Alquiler', ExpenseGroup::Alquiler, 25000, 25000, 'Inmobiliaria Duarte'],
        ['Gas del delivery', ExpenseGroup::Transporte, 500, 1800, null],
        ['Publicidad en redes', ExpenseGroup::Publicidad, 1000, 4500, null],
        ['Mantenimiento de la cafetera', ExpenseGroup::Mantenimiento, 1500, 7000, 'Servicios Técnicos RD'],
    ];

    public function __construct(private readonly CurrentCompany $empresaActiva) {}

    /**
     * Siembra gastos repartidos en los últimos meses.
     *
     * Repartidos y no todos hoy: la tabla dinámica cruza gasto contra tiempo, y con todo en un mismo
     * día no habría nada que cruzar — se vería una sola columna y no se podría juzgar si la pantalla
     * sirve.
     */
    public function sembrar(int $cuantos = 50, int $mesesAtras = 3): int
    {
        $empresaId = $this->empresaActiva->id();

        if ($empresaId === null) {
            return 0;
        }

        $cuenta = Account::query()->where('is_active', true)->orderByDesc('is_default')->first();

        if ($cuenta === null) {
            return 0;
        }

        $conceptos = $this->conceptos();
        $desde = now()->subMonths($mesesAtras)->startOfMonth();
        $dias = max(1, (int) $desde->diffInDays(now()));
        $siguiente = $this->ultimoNumero($empresaId);
        $creados = 0;

        DB::transaction(function () use ($cuantos, $conceptos, $cuenta, $empresaId, $desde, $dias, &$siguiente, &$creados): void {
            for ($i = 0; $i < $cuantos; $i++) {
                [$nombre, , $minimo, $maximo, $proveedor] = self::CATALOGO[array_rand(self::CATALOGO)];

                Expense::create([
                    'company_id' => $empresaId,
                    'code' => self::PREFIJO.str_pad((string) (++$siguiente), 6, '0', STR_PAD_LEFT),
                    'account_id' => $cuenta->id,
                    'expense_category_id' => $conceptos[$nombre]->id,
                    'supplier_name' => $proveedor,
                    // Importes con céntimos, que es como salen de verdad: con cifras redondas no se
                    // ve si las sumas de la tabla arrastran algún redondeo.
                    'amount' => number_format(random_int($minimo * 100, $maximo * 100) / 100, 2, '.', ''),
                    'description' => $nombre,
                    'paid_at' => $desde->copy()->addDays(random_int(0, $dias))->setTime(random_int(8, 19), random_int(0, 59)),
                ]);

                $creados++;
            }
        });

        return $creados;
    }

    /**
     * Se lleva SOLO los de mentira.
     *
     * El filtro por prefijo no es una comodidad: es lo que hace que esto no pueda borrar un gasto de
     * verdad ni equivocándose. Y aquí sí es borrado físico —son basura de pruebas, no historial—.
     */
    public function borrar(): int
    {
        $empresaId = $this->empresaActiva->id();

        if ($empresaId === null) {
            return 0;
        }

        return Expense::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $empresaId)
            ->where('code', 'like', self::PREFIJO.'%')
            ->forceDelete();
    }

    public function cuantosHay(): int
    {
        $empresaId = $this->empresaActiva->id();

        return $empresaId === null ? 0 : Expense::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $empresaId)
            ->where('code', 'like', self::PREFIJO.'%')
            ->count();
    }

    /**
     * Los conceptos que hacen falta, creados o reutilizados, y CLASIFICADOS.
     *
     * La clasificación solo se pone si falta: si alguien ya decidió que «Luz» es Servicios, o que es
     * otra cosa, no se le pisa la decisión por sembrar datos de prueba.
     *
     * @return array<string, ExpenseCategory>
     */
    private function conceptos(): array
    {
        $conceptos = [];

        foreach (self::CATALOGO as [$nombre, $categoria]) {
            $concepto = ExpenseCategory::query()->where('name', $nombre)->first();

            if ($concepto === null) {
                $concepto = ExpenseCategory::create(['name' => $nombre, 'category' => $categoria, 'is_active' => true]);
            } elseif ($concepto->category === null) {
                $concepto->update(['category' => $categoria]);
            }

            $conceptos[$nombre] = $concepto;
        }

        return $conceptos;
    }

    /** Por dónde va la numeración de los de prueba, para no chocar con el índice único. */
    private function ultimoNumero(int $empresaId): int
    {
        $ultimo = Expense::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $empresaId)
            ->where('code', 'like', self::PREFIJO.'%')
            ->orderByDesc('code')
            ->value('code');

        return $ultimo === null ? 0 : (int) substr((string) $ultimo, strlen(self::PREFIJO));
    }
}
