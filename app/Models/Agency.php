<?php

namespace App\Models;

use App\Enums\AgencyType;
use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A partner: a main office, an outlet, or an ITP.
 *
 * Every agency is an independent permission scope. A user belongs to exactly one
 * (or to none, meaning platform staff), and what they may do there comes from the
 * roles assigned to them — never from the agency's type or its place in the tree.
 * `parent` is descriptive only; see the migration.
 */
#[Fillable([
    'code', 'name', 'type', 'parent_id', 'is_active',
    'contact_email', 'contact_phone', 'address', 'logo_path',
])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AgencyType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Roles this agency owns. Only its own members can be given them.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * The agency's e-wallet. Bound to the agency, never to a user — every member
     * draws on this one balance.
     *
     * @return HasOne<Wallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * The office this agency reports to. Reporting only — not an authorization path.
     *
     * @return BelongsTo<Agency, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Agency, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @param  Builder<Agency>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Agencies the given user may see: platform staff see the whole partner
     * network, an agency member sees only their own.
     *
     * This is the list-level counterpart to AgencyPolicy's per-instance check —
     * without it a member holding agency.view could enumerate every partner from
     * the index even though they could not open any of them.
     *
     * @param  Builder<Agency>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        $query->whereKey($user->agency_id);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /**
     * Public URL for the logo, or null when none is set. Built from the stored path
     * so the disk or domain can change without touching any row.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * Fallback when there is no logo: up to two letters from the agency name.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }
}
