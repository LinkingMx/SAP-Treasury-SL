<?php

namespace Database\Factories;

use App\Models\VendorPaymentBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VendorPaymentInvoice>
 */
class VendorPaymentInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_id' => VendorPaymentBatch::factory(),
            'card_code' => 'P'.fake()->numerify('####'),
            'card_name' => fake()->company(),
            'doc_date' => now()->toDateString(),
            'transfer_date' => now()->toDateString(),
            'transfer_account' => '1020-001-000',
            'line_num' => 0,
            'doc_entry' => fake()->numberBetween(10000, 99999),
            'invoice_type' => 'it_PurchaseInvoice',
            'sum_applied' => fake()->randomFloat(2, 100, 50000),
            'proveedor_ref' => 'IN'.fake()->numerify('#####'),
            'sap_doc_num' => null,
            'error' => null,
        ];
    }
}
