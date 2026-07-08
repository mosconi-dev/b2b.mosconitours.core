<?php

namespace App\Services\Rbac;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Thin seam for security/audit logging. Expanding coverage later is just more
 * calls to log() — the audit_logs schema already anticipates it.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, ?Model $auditable = null, array $properties = [], ?string $description = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent() ? substr((string) Request::userAgent(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
