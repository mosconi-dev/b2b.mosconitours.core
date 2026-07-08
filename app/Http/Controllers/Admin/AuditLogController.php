<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        $logs = AuditLog::with('user:id,name,email')
            ->latest('created_at')
            ->latest('id')
            ->paginate(30);

        return view('admin.audit-logs.index', compact('logs'));
    }
}
