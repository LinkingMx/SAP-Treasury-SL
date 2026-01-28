<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Cuenta '.fake()->randomElement(['Operativa', 'Nómina', 'Proveedores', 'Principal']),
            'account' => fake()->numerify('####-####-##########'),
            'sap_bank_key' => null,
        ];
    }

    /**
     * Indicate that the bank account has a SAP bank key configured.
     */
    public function withSapBankKey(?string $key = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sap_bank_key' => $key ?? fake()->numerify('####-###-###'),
        ]);
    }
}
