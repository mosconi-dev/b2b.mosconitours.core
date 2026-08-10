<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'label', 'description', 'is_system', 'agency_id'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * The agency that owns this role, or null for a platform-level role.
     *
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Roles the given user may see and manage: platform staff manage every role,
     * an agency member manages only their own agency's.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        $query->where('agency_id', $user->agency_id);
    }

    /**
     * A role may only ever be held by a user in the same scope — an agency's role
     * cannot leak to another agency, and a platform role cannot leak to an agency.
     */
    public function isInScopeFor(User $user): bool
    {
        return $this->agency_id === $user->agency_id;
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function hasPermission(string $name): bool
    {
        return $this->permissions->contains('name', $name);
    }
}
