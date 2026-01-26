<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearningRule extends Model
{
    use HasFactory;

    /**
     * Rule types for hierarchical classification.
     */
    public const TYPE_ACTOR = 'ACTOR';

    public const TYPE_RFC = 'RFC';

    public const TYPE_CONCEPTO = 'CONCEPTO';

    /**
     * Priority levels (1 = highest).
     */
    public const PRIORITY_HIGH = 1;

    public const PRIORITY_MEDIUM = 2;

    public const PRIORITY_LOW = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pattern',
        'actor',
        'rfc',
        'match_type',
        'rule_type',
        'priority',
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
            'priority' => 'integer',
        ];
    }

    /**
     * Scope to filter by rule type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('rule_type', $type);
    }

    /**
     * Scope to order by priority (1 = highest).
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderByDesc('confidence_score');
    }

    /**
     * Find matching rule by RFC (Level 1 - highest priority).
     */
    public static function findByRfc(string $rfc): ?self
    {
        return static::where('rfc', strtoupper($rfc))
            ->ofType(self::TYPE_RFC)
            ->byPriority()
            ->first();
    }

    /**
     * Find matching rule by Actor name (Level 1).
     */
    public static function findByActor(string $actor): ?self
    {
        $actor = strtoupper(trim($actor));

        return static::ofType(self::TYPE_ACTOR)
            ->where(function (Builder $q) use ($actor) {
                $q->whereRaw('UPPER(actor) = ?', [$actor])
                    ->orWhereRaw('UPPER(actor) LIKE ?', ['%'.$actor.'%'])
                    ->orWhereRaw('? LIKE CONCAT(\'%\', UPPER(actor), \'%\')', [$actor]);
            })
            ->byPriority()
            ->first();
    }

    /**
     * Find matching rule by Concept (Level 2).
     */
    public static function findByConcept(string $concept): ?self
    {
        $concept = strtoupper(trim($concept));

        return static::ofType(self::TYPE_CONCEPTO)
            ->where(function (Builder $q) use ($concept) {
                $q->whereRaw('UPPER(pattern) = ?', [$concept])
                    ->orWhereRaw('UPPER(pattern) LIKE ?', ['%'.$concept.'%']);
            })
            ->byPriority()
            ->first();
    }

    /**
     * Find matching rule by pattern (legacy - contains match).
     */
    public static function findByPattern(string $text): ?self
    {
        $text = strtoupper(trim($text));

        return static::whereRaw('UPPER(?) LIKE CONCAT(\'%\', UPPER(pattern), \'%\')', [$text])
            ->byPriority()
            ->first();
    }

    /**
     * Hierarchical match: RFC -> Actor -> Concept -> Pattern.
     *
     * @return array{rule: self|null, level: int, confidence: int}
     */
    public static function findHierarchicalMatch(?string $rfc, ?string $actor, ?string $concept, ?string $fullText = null): array
    {
        // Level 1: RFC match (highest confidence)
        if ($rfc) {
            $rule = static::findByRfc($rfc);
            if ($rule) {
                return ['rule' => $rule, 'level' => 1, 'confidence' => 95];
            }
        }

        // Level 1: Actor match
        if ($actor) {
            $rule = static::findByActor($actor);
            if ($rule) {
                return ['rule' => $rule, 'level' => 1, 'confidence' => 90];
            }
        }

        // Level 2: Concept match
        if ($concept) {
            $rule = static::findByConcept($concept);
            if ($rule) {
                return ['rule' => $rule, 'level' => 2, 'confidence' => 80];
            }
        }

        // Level 3: Pattern match (legacy fallback)
        if ($fullText) {
            $rule = static::findByPattern($fullText);
            if ($rule) {
                return ['rule' => $rule, 'level' => 3, 'confidence' => 70];
            }
        }

        // No match
        return ['rule' => null, 'level' => 0, 'confidence' => 0];
    }

    /**
     * Legacy: Scope to find rules matching a description.
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
     * Legacy: Find the best matching rule for a description.
     */
    public static function findBestMatch(string $description): ?self
    {
        return static::matchingDescription($description)->first();
    }
}
