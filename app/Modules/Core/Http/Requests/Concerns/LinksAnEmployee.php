<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests\Concerns;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * La regla de «esta cuenta es de este empleado», compartida por el alta y la edición de usuario.
 *
 * Va en un sitio y no en dos porque decide de quién es una cuenta, y de ahí sale qué entregas ve un
 * repartidor. Dos copias de esta condición se separarían y una de las dos dejaría pasar un vínculo
 * que la otra rechaza.
 *
 * NO hay índice único en `employees.user_id`, a propósito: la tabla lleva SoftDeletes y en Postgres
 * dos filas `(user_id = 5, deleted_at = NULL)` no colisionan —`NULL` nunca es igual a `NULL` en un
 * índice único—, así que el índice daría una garantía falsa. La condición se aplica aquí, donde sí
 * se cumple.
 */
trait LinksAnEmployee
{
    /**
     * @return array<int, mixed>
     */
    protected function employeeLinkRules(?int $currentUserId = null): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                // Un empleado que ya tiene cuenta no se le puede colgar a otra: sería robarle el
                // acceso, y sus entregas pasarían a aparecer en el móvil de otra persona. Al editar,
                // su propia cuenta sí vale: es el caso de guardar el formulario sin tocar el campo.
                ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $currentUserId)),
        ];
    }

    /**
     * Mensaje propio: el de `exists` por omisión —«El campo seleccionado no es válido»— no dice nada
     * a quien acaba de elegir un empleado de una lista que el sistema mismo le ofreció.
     */
    protected function employeeLinkMessage(Validator $validator): void
    {
        $validator->setCustomMessages([
            'employee_id.exists' => 'Ese empleado ya tiene su propia cuenta de acceso.',
        ]);
    }
}
