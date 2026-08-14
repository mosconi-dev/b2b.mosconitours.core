<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One agency's markup configuration. Exactly one per agency, enforced by a unique index.
 *
 * A strategy is a *level* in the price ladder, not an alternative to another level: the
 * Main Office's and an agency's both apply, and their contributions sum. What competes
 * is the rules inside one strategy, of which exactly one may contribute.
 *
 * Belongs to an agency and never to a user — the table has no `user_id`, so a
 * user-owned strategy is not a storable thing rather than a rule someone must remember
 * to enforce.
 */
#[Fillable(['agency_id', 'name', 'is_active'])]
class PricingStrategy extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<PricingRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(PricingRule::class)->orderBy('priority')->orderBy('id');
    }

    /**
     * The rules the matcher will consider, cheapest query first: active only, in
     * priority order. Date windows are checked in PHP because they depend on the
     * context's travel date, not on today.
     *
     * @return HasMany<PricingRule, $this>
     */
    public function activeRules(): HasMany
    {
        return $this->rules()->where('is_active', true);
    }
}
