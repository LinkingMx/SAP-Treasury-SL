<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Sucursal '.fake()->city(),
            'sap_database' => 'SBO_'.fake()->regexify('[A-Z]{5,10}'),
            'sap_branch_id' => fake()->numberBetween(1, 100),
            'ceco' => 'CC-'.fake()->numerify('###'),
        ];
    }
}
