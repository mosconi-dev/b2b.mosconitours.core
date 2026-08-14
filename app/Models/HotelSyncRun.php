<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One catalogue sync, and what it could not do.
 *
 * The failures column is the point: a sync that skipped eleven cities and says so
 * can be re-run to completion, where one that aborted on the first failure and
 * reported a single string cannot.
 */
class HotelSyncRun extends Model
{
    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'scope', 'target', 'status', 'processed', 'failed', 'failures', 'message',
        'user_id', 'started_at', 'finished_at',
    ];

    /**
     * Matches the column defaults. Without these the counters are null in memory
     * until the row is re-read, and "nothing failed" reads as "failed is not zero".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::RUNNING,
        'processed' => 0,
        'failed' => 0,
    ];

    protected $casts = [
        'processed' => 'integer',
        'failed' => 'integer',
        'failures' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Note one thing that could not be fetched, and keep going.
     */
    public function recordFailure(string $target, ?string $label, string $reason): void
    {
        $failures = $this->failures ?? [];
        $failures[] = array_filter([
            'target' => $target,
            'label' => $label,
            'reason' => mb_substr($reason, 0, 300),
        ]);

        $this->failures = $failures;
        $this->failed = count($failures);
    }

    public function durationSeconds(): ?int
    {
        return $this->started_at && $this->finished_at
            ? (int) $this->started_at->diffInSeconds($this->finished_at)
            : null;
    }
}
