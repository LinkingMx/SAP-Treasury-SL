<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearningRule extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pattern',
        'match_type',
        'sap_account_code',
        'sap_account_name',
        'confidence_score',
        'source',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
        ];
    }

    /**
     * Scope to find rules matching a description.
     */
    public function scopeMatchingDescription(Builder $query, string $description): Builder
    {
        return $query->where(function (Builder $q) use ($description) {
            $q->where(function (Builder $exact) use ($description) {
                $exact->where('match_type', 'exact')
                    ->whereRaw('LOWER(pattern) = LOWER(?)', [$description]);
            })->orWhere(function (Builder $contains) use ($description) {
                $contains->where('match_type', 'contains')
                    ->whereRaw('LOWER(?) LIKE CONCAT(\'%\', LOWER(pattern), \'%\')', [$description]);
            });
        })->orderByDesc('confidence_score');
    }

    /**
     * Find the best matching rule for a description.
     */
    public static function findBestMatch(string $description): ?self
    {
        return static::matchingDescription($description)->first();
    }
}
