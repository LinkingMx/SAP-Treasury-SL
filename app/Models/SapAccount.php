<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SapAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'account_type',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the branch that owns this account.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Scope to filter active accounts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by branch.
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Find an account by code for a specific branch.
     */
    public static function findByCode(int $branchId, string $code): ?self
    {
        return static::forBranch($branchId)->where('code', $code)->first();
    }

    /**
     * Get chart of accounts for a branch as array.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function getChartOfAccounts(int $branchId): array
    {
        return static::forBranch($branchId)
            ->active()
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($account) => [
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->toArray();
    }
}
