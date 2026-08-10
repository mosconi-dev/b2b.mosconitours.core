<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Rbac\RbacCache;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_active', 'tbo_environment', 'agency_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Per-request memoization of resolved permission names.
     *
     * @var array<int, string>|null
     */
    protected ?array $permissionNamesCache = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The single agency this user belongs to, or null for platform staff.
     *
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Platform staff are not bound to an agency, so no agency scope applies to them.
     * This is not a permission bypass — they still need the ability like anyone else.
     */
    public function isPlatformStaff(): bool
    {
        return $this->agency_id === null;
    }

    /**
     * Users the given user may see and manage: platform staff manage everyone, an
     * agency member manages only their own agency's people.
     *
     * @param  Builder<User>  $query
     */
    public function scopeVisibleTo(Builder $query, self $user): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        $query->where('agency_id', $user->agency_id);
    }

    public function isInScopeFor(self $actor): bool
    {
        return $actor->isPlatformStaff() || $this->agency_id === $actor->agency_id;
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    /**
     * @param  array<int, string>  $names
     */
    public function hasAnyRole(array $names): bool
    {
        return $this->roles->pluck('name')->intersect($names)->isNotEmpty();
    }

    /**
     * The union of permission names granted by all of the user's roles.
     *
     * Resolved in two queries, memoized on the instance for the request, and
     * cached across requests via RbacCache. Invalidation is explicit (pivot
     * sync() fires no model events) — see RbacCache::flushUser/flushRole.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return $this->permissionNamesCache ??= app(RbacCache::class)->remember(
            $this,
            fn (): array => $this->roles()
                ->with('permissions:id,name')
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->pluck('name')
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @param  mixed  $context  Reserved for future branch/organization scoping (no-op today).
     */
    public function hasPermissionTo(string $name, mixed $context = null): bool
    {
        return in_array($name, $this->permissionNames(), true);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionNamesCache = null;
        app(RbacCache::class)->flushUser($this);
    }
}
