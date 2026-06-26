<?php

namespace Database\Factories;

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\PaymentOrder;
use App\Models\SettlementUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentOrder>
 */
class PaymentOrderFactory extends Factory
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
            'external_settlement_id' => ExternalSettlement::factory(),
            'acquirer_id' => Acquirer::factory(),
            'branch_id' => Branch::factory(),
            'parrot_payment_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'order_reference' => (string) fake()->numerify('######-P-####'),
            'payment_total' => fake()->randomFloat(2, 100, 5000),
            'external_reference' => (string) fake()->numerify('######'),
            'match_method' => PaymentOrder::METHOD_AUTO_EXACT,
            'match_diff' => 0,
            'matched_at' => now(),
            'matched_by_user_id' => null,
        ];
    }
}
