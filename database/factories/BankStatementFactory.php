<?php

namespace Database\Factories;

use App\Enums\BankStatementStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankStatement>
 */
class BankStatementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 month', 'now');
        $yearMonth = $date->format('Y-m');

        return [
            'branch_id' => Branch::factory(),
            'bank_account_id' => BankAccount::factory(),
            'user_id' => User::factory(),
            'statement_date' => $date,
            'statement_number' => $yearMonth.'-'.str_pad((string) fake()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'original_filename' => fake()->word().'.xlsx',
            'rows_count' => fake()->numberBetween(10, 100),
            'status' => BankStatementStatus::Pending,
            'sap_doc_entry' => null,
            'sap_error' => null,
            'payload' => null,
        ];
    }

    /**
     * Indicate that the bank statement was sent successfully to SAP.
     */
    public function sent(?int $docEntry = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankStatementStatus::Sent,
            'sap_doc_entry' => $docEntry ?? fake()->numberBetween(1000, 99999),
            'sap_error' => null,
        ]);
    }

    /**
     * Indicate that the bank statement failed to send to SAP.
     */
    public function failed(?string $error = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankStatementStatus::Failed,
            'sap_doc_entry' => null,
            'sap_error' => $error ?? 'Connection error: SAP server unavailable',
        ]);
    }
}
