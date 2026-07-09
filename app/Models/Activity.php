<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'action', 'description', 'method', 'route', 'url', 'ip_address', 'user_agent', 'created_at',
])]
class Activity extends Model
{
    protected $table = 'activity_logs';

    /**
     * Activity rows are append-only — only created_at is tracked.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * Human-readable line: the stored description, or a humanized action name.
     */
    public function label(): string
    {
        return $this->description ?: Str::headline(str_replace('.', ' ', $this->action));
    }
}
