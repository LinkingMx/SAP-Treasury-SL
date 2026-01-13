<?php

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Batch>
 */
class BatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'branch_id' => Branch::factory(),
            'bank_account_id' => BankAccount::factory(),
            'user_id' => User::factory(),
            'filename' => fake()->word().'.xlsx',
            'total_records' => fake()->numberBetween(1, 100),
            'total_debit' => fake()->randomFloat(2, 1000, 100000),
            'total_credit' => fake()->randomFloat(2, 1000, 100000),
            'status' => BatchStatus::Pending,
            'processed_at' => now(),
        ];
    }
}
