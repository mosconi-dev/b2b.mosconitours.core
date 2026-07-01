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

        $logs = TboAirApiLog::query()
            // Exclude the heavy `response` JSON from the list + ORDER BY so MySQL
            // never sorts megabyte blobs (avoids "Out of sort memory"). It's
            // fetched lazily via show() when a row is expanded.
            ->select(['id', 'type', 'endpoint', 'status_code', 'successful', 'duration_ms', 'user_id', 'error', 'request', 'created_at'])
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

    public function show(TboAirApiLog $apiLog): JsonResponse
    {
        return response()->json([
            'response' => $apiLog->response,
        ]);
    }
}
