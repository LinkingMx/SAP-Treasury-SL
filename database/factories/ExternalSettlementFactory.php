<?php

namespace Database\Factories;

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\SettlementUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExternalSettlement>
 */
class ExternalSettlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'upload_id' => SettlementUpload::factory(),
            'acquirer_id' => Acquirer::factory(),
            'branch_id' => Branch::factory(),
            'transaction_date' => '2026-05-15',
            'transaction_time' => fake()->time('H:i:s'),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'card_type' => fake()->randomElement(['CREDITO', 'DEBITO']),
            'card_brand' => fake()->randomElement(['VISA', 'MASTER', 'AMEX']),
            'authorization' => (string) fake()->numerify('######'),
            'reference' => (string) fake()->numerify('############'),
            'terminal' => (string) fake()->numerify('TERM####'),
            'operation_type' => 'VENTA',
            'status' => 'Aplicado',
            'match_status' => ExternalSettlement::MATCH_UNMATCHED,
            'raw' => [],
        ];
    }
}
