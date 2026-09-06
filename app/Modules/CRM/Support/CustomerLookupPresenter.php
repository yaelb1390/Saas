<?php

declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\CRM\Models\Customer;

/**
 * Busca clientes para el mostrador y los aplana a datos serializables.
 *
 * POR QUÉ EXISTE. Las pantallas de cobro cargaban **todos** los clientes activos de la empresa en un
 * `<select>`, en cada visita. Con doscientos clientes ya pesa; con dos mil, la pantalla tarda en
 * abrir y el desplegable es inútil —nadie encuentra a nadie desplazando—. El catálogo de productos
 * ya se había migrado a búsqueda bajo demanda por exactamente este motivo; los clientes se habían
 * quedado atrás.
 *
 * SE BUSCA POR LAS CUATRO COSAS por las que un cliente se identifica en el mostrador: su nombre, su
 * RNC, su cédula y su teléfono. Quien llama por teléfono para recoger una pieza dice el número, no
 * el nombre con el que lo dieron de alta.
 *
 * El aislamiento por empresa lo pone el CompanyScope del modelo: aquí no se filtra a mano.
 */
final class CustomerLookupPresenter
{
    /**
     * Los clientes que coinciden con lo tecleado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $termino, int $limite = 15): array
    {
        $termino = trim($termino);

        if ($termino === '') {
            return [];
        }

        $consulta = Customer::query()->where('is_active', true);

        return BusquedaTexto::enCualquiera($consulta, ['name', 'tax_id', 'cedula', 'phone'], $termino)
            /*
             * Lo que EMPIEZA por lo tecleado, primero.
             *
             * Quien escribe «ram» está pensando en «Ramírez», no en «Ferretería El Ram». Sin este
             * orden hay que leerse la lista entera para encontrar lo que se buscaba; con él, lo que
             * se quería suele ser la primera fila y basta con pulsar Enter.
             */
            ->orderByRaw(
                'case when lower(name) like ?'.BusquedaTexto::ESCAPE.' then 0 else 1 end',
                [BusquedaTexto::prefijo($termino)],
            )
            ->orderBy('name')
            ->limit($limite)
            ->get(['id', 'name', 'tax_id', 'cedula', 'phone'])
            ->map(fn (Customer $cliente): array => [
                'id' => $cliente->id,
                'name' => $cliente->name,
                /*
                 * El identificador fiscal que se usará al facturar: primero el RNC y, si no lo tiene,
                 * la cédula. Se resuelve AQUÍ y no en el navegador para que la pantalla no tenga que
                 * repetir la regla —y para que no discrepe de la que aplica el comprobante—.
                 */
                'tax_id' => $cliente->tax_id ?: $cliente->cedula,
                'phone' => $cliente->phone,
            ])
            ->all();
    }
}
