<?php

namespace App\Services\Activity;

use App\Models\Activity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records what a user is doing inside the app (navigation + key actions) —
 * a complement to the TBO API logs. One row per activity, append-only.
 */
class ActivityLogger
{
    public function log(string $action, ?string $description = null, ?Authenticatable $user = null): ?Activity
    {
        $user ??= Auth::user();

        if (! $user) {
            return null;
        }

        $request = request();

        return Activity::create([
            'user_id' => $user->getAuthIdentifier(),
            'action' => $action,
            'description' => $description,
            'method' => $request?->method(),
            'route' => $request?->route()?->getName(),
            'url' => $request ? Str::limit($request->getRequestUri(), 250, '') : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'created_at' => now(),
        ]);
    }
}
