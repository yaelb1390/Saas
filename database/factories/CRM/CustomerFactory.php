<?php

declare(strict_types=1);

namespace Database\Factories\CRM;

use App\Modules\CRM\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // Formato dominicano: es el que llega por WhatsApp y con el que se busca.
            'phone' => '1809'.fake()->numerify('#######'),
            'cedula' => fake()->numerify('001-#######-#'),
            'is_active' => true,
        ];
    }

    /** Archivado: sigue existiendo pero deja de ofrecerse al vender. */
    public function archivado(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
