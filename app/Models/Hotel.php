<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hotel extends Model
{
    protected $fillable = [
        'source', 'code', 'city_code', 'country_code', 'name', 'address', 'rating',
        'latitude', 'longitude', 'description', 'facilities', 'attractions', 'images',
        'phone', 'email', 'website', 'pin_code', 'checkin_time', 'checkout_time',
        'detailed_at', 'synced_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'facilities' => 'array',
        'attractions' => 'array',
        'images' => 'array',
        'detailed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<HotelCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HotelCity::class, 'city_code', 'code');
    }

    /**
     * Hotels whose descriptions and images have not been fetched yet — the queue
     * the enrichment pass works through.
     *
     * @param  Builder<static>  $query
     */
    public function scopeNeedingDetail(Builder $query): void
    {
        $query->whereNull('detailed_at');
    }

    public function thumbnail(): ?string
    {
        return ($this->images ?? [])[0] ?? null;
    }
}
