<?php

declare(strict_types=1);

namespace Database\Factories\SocialCommerce;

use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
final class RuleFactory extends Factory
{
    protected $model = Rule::class;

    /**
     * `product_id` no tiene valor por omisión: `Inventory\Models\Product` no tiene factory propia
     * en este proyecto (se crea a mano en seeders/tests, ver auditoría de convenciones de base de
     * datos). Quien use esta factory debe pasar `product_id` explícitamente.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Precio '.fake()->words(2, true),
            'zernio_account_id' => fake()->uuid(),
            'trigger' => 'comment',
            'price_mode' => 'normal',
            'keywords' => ['precio', 'cuanto', 'vale'],
            'match_mode' => 'word',
            'typo_tolerance' => true,
            'also_in_dms' => false,
            'follow_gate' => false,
            'dm_delay_seconds' => 0,
            'status' => RuleStatus::Draft,
        ];
    }

    public function activa(): static
    {
        return $this->state(fn (): array => [
            'status' => RuleStatus::Active,
            'zernio_automation_id' => fake()->uuid(),
            'last_synced_at' => now(),
        ]);
    }
}
