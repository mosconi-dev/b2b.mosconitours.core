<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelCity extends Model
{
    protected $fillable = ['source', 'code', 'country_code', 'name', 'is_enabled', 'hotels_count', 'hotels_synced_at'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'hotels_count' => 'integer',
        'hotels_synced_at' => 'datetime',
    ];

    /**
     * @return HasMany<Hotel, $this>
     */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class, 'city_code', 'code');
    }

    /**
     * The cities we actually carry hotels for.
     *
     * @param  Builder<static>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }
}
