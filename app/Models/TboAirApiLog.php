<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TboAirApiLog extends Model
{
    protected $fillable = [
        'type',
        'environment',
        'endpoint',
        'status_code',
        'successful',
        'duration_ms',
        'user_id',
        'request',
        'response',
        'error',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'successful' => 'boolean',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A short, human-readable summary of the call (route for searches).
     */
    public function summary(): string
    {
        if ($this->type === 'search') {
            $segments = $this->request['Segments'] ?? [];

            if (! empty($segments)) {
                $first = $segments[0];
                $route = ($first['Origin'] ?? '?').' → '.($first['Destination'] ?? '?');
                $extra = count($segments) > 1 ? ' +'.(count($segments) - 1) : '';

                return $route.$extra;
            }
        }

        return ucfirst($this->type);
    }
}
