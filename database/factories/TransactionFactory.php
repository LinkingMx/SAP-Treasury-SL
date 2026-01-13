<?php

namespace Database\Factories;

use App\Models\Batch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isDebit = fake()->boolean();

        return [
            'batch_id' => Batch::factory(),
            'sequence' => fake()->numberBetween(1, 100),
            'due_date' => fake()->date(),
            'memo' => fake()->sentence(4),
            'debit_amount' => $isDebit ? fake()->randomFloat(2, 100, 10000) : 0,
            'credit_amount' => $isDebit ? 0 : fake()->randomFloat(2, 100, 10000),
            'counterpart_account' => fake()->numerify('####-####'),
            'sap_number' => null,
            'error' => null,
        ];
    }
}
