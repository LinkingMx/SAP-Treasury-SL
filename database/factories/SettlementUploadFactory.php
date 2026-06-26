<?php

namespace Database\Factories;

use App\Enums\SettlementUploadStatus;
use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SettlementUpload>
 */
class SettlementUploadFactory extends Factory
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
            'acquirer_id' => Acquirer::factory(),
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'original_name' => fake()->word().'.xlsx',
            'stored_path' => null,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => SettlementUploadStatus::Pending,
            'total_rows' => 0,
            'matched_rows' => 0,
            'unmatched_rows' => 0,
        ];
    }
}
