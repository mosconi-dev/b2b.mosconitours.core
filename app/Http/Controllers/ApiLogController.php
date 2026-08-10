<?php

namespace App\Http\Controllers;

use App\Models\TboAirApiLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiLogController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query('type');

        $logs = TboAirApiLog::visibleTo($request->user())
            // Exclude the heavy `response` JSON from the list + ORDER BY so MySQL
            // never sorts megabyte blobs (avoids "Out of sort memory"). It's
            // fetched lazily via show() when a row is expanded.
            ->select(['id', 'type', 'environment', 'endpoint', 'status_code', 'successful', 'duration_ms', 'user_id', 'agency_id', 'error', 'request', 'created_at'])
            ->with('user:id,name')
            ->when(in_array($type, ['authenticate', 'search'], true), fn ($q) => $q->where('type', $type))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('api-logs.index', [
            'logs' => $logs,
            'type' => $type,
        ]);
    }

    /**
     * The raw provider response for one row. Guarded explicitly — the route only
     * proves the caller may view API logs, not that they may view THIS one.
     */
    public function show(Request $request, TboAirApiLog $apiLog): JsonResponse
    {
        abort_unless($apiLog->isVisibleTo($request->user()), 403);

        return response()->json([
            'response' => $apiLog->response,
        ]);
    }
}
