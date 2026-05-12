<?php

namespace Database\Factories;

use App\Models\CustomerPaymentBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerPaymentInvoice>
 */
class CustomerPaymentInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_id' => CustomerPaymentBatch::factory(),
            'card_code' => 'C'.fake()->numerify('####'),
            'card_name' => fake()->company(),
            'doc_date' => now()->toDateString(),
            'transfer_date' => now()->toDateString(),
            'transfer_account' => '1020-001-000',
            'line_num' => 0,
            'doc_entry' => fake()->numberBetween(10000, 99999),
            'invoice_type' => 'it_Invoice',
            'sum_applied' => fake()->randomFloat(2, 100, 50000),
            'sap_doc_num' => null,
            'error' => null,
        ];
    }
}
