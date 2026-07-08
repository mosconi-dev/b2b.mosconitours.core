<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'event', 'auditable_type', 'auditable_id',
    'description', 'properties', 'ip_address', 'user_agent', 'created_at',
])]
class AuditLog extends Model
{
    /**
     * Audit rows are append-only — only created_at is tracked.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Human-readable event, e.g. "user.deactivated" -> "User Deactivated".
     */
    public function label(): string
    {
        return Str::headline(str_replace('.', ' ', $this->event));
    }

    /**
     * The affected record as a short label, e.g. "User #5".
     */
    public function target(): ?string
    {
        return $this->auditable_type
            ? class_basename($this->auditable_type).' #'.$this->auditable_id
            : null;
    }
}
