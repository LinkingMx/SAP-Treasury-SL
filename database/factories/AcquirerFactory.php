<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Acquirer>
 */
class AcquirerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('ACQ_#####')),
            'name' => fake()->company(),
            'kind' => fake()->randomElement(['BANK', 'DELIVERY', 'WALLET']),
            'parrot_payment_type_names' => ['CREDITO', 'DEBITO'],
            'amount_tolerance' => 0.10,
            'time_window_seconds' => null,
            'active' => true,
        ];
    }

    /**
     * A card-acquiring bank (MIFEL/AFIRME style).
     */
    public function bank(): static
    {
        return $this->state(fn (): array => [
            'kind' => 'BANK',
            'parrot_payment_type_names' => ['CREDITO', 'DEBITO', 'AMEX'],
            'amount_tolerance' => 0.10,
        ]);
    }

    /**
     * A delivery aggregator (Rappi/Uber style).
     */
    public function delivery(string $paymentType = 'Rappi'): static
    {
        return $this->state(fn (): array => [
            'kind' => 'DELIVERY',
            'parrot_payment_type_names' => [$paymentType],
            'amount_tolerance' => 0.50,
        ]);
    }
}
