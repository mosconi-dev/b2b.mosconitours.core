<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelCountry extends Model
{
    protected $fillable = ['source', 'code', 'name'];

    /**
     * @return HasMany<HotelCity, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(HotelCity::class, 'country_code', 'code');
    }
}
