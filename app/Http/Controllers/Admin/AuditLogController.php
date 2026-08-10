<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::visibleTo($request->user())
            ->with('user:id,name,email')
            ->latest('created_at')
            ->latest('id')
            ->paginate(30);

        return view('admin.audit-logs.index', compact('logs'));
    }
}
