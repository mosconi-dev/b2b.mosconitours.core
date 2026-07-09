<?php

namespace App\Http\Middleware;

use App\Services\Activity\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs a user's page navigation. Only successful GET page loads by a signed-in
 * user on a named route are recorded — assets, AJAX/JSON, redirects and errors
 * are skipped so the trail reads as a journey, not noise.
 */
class LogActivity
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->record($request, $response);

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        if (! Auth::check() || $request->method() !== 'GET' || $request->expectsJson()) {
            return;
        }

        $name = $request->route()?->getName();

        if (! $name || $response->getStatusCode() >= 400) {
            return;
        }

        $this->logger->log('page.viewed', $this->label($name));
    }

    /**
     * Humanize a route name into a page label, e.g. "admin.users.index" -> "Admin · Users".
     */
    private function label(string $route): string
    {
        $segments = array_values(array_filter(
            explode('.', $route),
            fn (string $s): bool => $s !== 'index',
        ));

        return collect($segments)
            ->map(fn (string $s): string => Str::headline(str_replace('-', ' ', $s)))
            ->implode(' · ');
    }
}
