<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Support;

use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\HR\Models\Employee;

/**
 * Si el repartidor está libre, repartiendo, o fuera de servicio.
 *
 * SE DEDUCE, NO SE DECLARA, y esa es la decisión de fondo. Un interruptor que él pulsa se queda en
 * «disponible» el día que se le olvida tocarlo —que es justo el día con prisa—, y quien asigna en el
 * local le echa encima otra parada creyéndolo libre. Deducirlo de sus entregas no se puede olvidar.
 *
 * Vive aquí y no en el modelo `Employee` porque depende de las entregas: HR no tiene por qué saber
 * nada del reparto. Y en una clase propia y no en un controlador porque lo usan dos pantallas —el
 * portal de entregas y el perfil—, y la misma regla escrita dos veces acaba divergiendo.
 */
final class EstadoDelRepartidor
{
    public const DISPONIBLE = 'disponible';

    public const EN_ENTREGA = 'en_entrega';

    public const FUERA = 'fuera';

    public static function de(Employee $empleado): string
    {
        // Fuera de servicio manda sobre todo lo demás: una ficha desactivada no reparte, lleve lo que
        // lleve a medias.
        if (! $empleado->is_active) {
            return self::FUERA;
        }

        $enRuta = Delivery::query()
            ->where('employee_id', $empleado->id)
            ->where('status', DeliveryStatus::InTransit)
            ->exists();

        return $enRuta ? self::EN_ENTREGA : self::DISPONIBLE;
    }

    /** Lo que se lee en pantalla. */
    public static function label(string $estado): string
    {
        return match ($estado) {
            self::EN_ENTREGA => 'En entrega',
            self::FUERA => 'Fuera de servicio',
            default => 'Disponible',
        };
    }
}
