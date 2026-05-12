<?php

namespace Database\Factories;

use App\Enums\CustomerPaymentBatchStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerPaymentBatch>
 */
class CustomerPaymentBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'branch_id' => Branch::factory(),
            'bank_account_id' => BankAccount::factory(),
            'user_id' => User::factory(),
            'filename' => fake()->word().'.xlsx',
            'process_date' => now()->toDateString(),
            'total_invoices' => fake()->numberBetween(1, 20),
            'total_payments' => fake()->numberBetween(1, 10),
            'total_amount' => fake()->randomFloat(2, 1000, 100000),
            'status' => CustomerPaymentBatchStatus::Pending,
            'processed_at' => now(),
        ];
    }
}
