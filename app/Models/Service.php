<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'total_spots',
        'available_from',
        'available_until',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total_spots' => 'integer',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function isWithinAvailabilityWindow(): bool
    {
        $now = now();

        return $now->betweenIncluded($this->available_from, $this->available_until);
    }
}
