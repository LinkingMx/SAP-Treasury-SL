<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Get the layout templates for the bank.
     */
    public function layoutTemplates(): HasMany
    {
        return $this->hasMany(BankLayoutTemplate::class);
    }

    /**
     * Get the batches for the bank.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
