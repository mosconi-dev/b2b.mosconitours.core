<?php

namespace App\Services\TboAir;

use App\Models\TboAirApiLog;
use App\Services\TboAir\Exceptions\TboAirException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Throwable;

class TboAirClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * The resolved environment this client is bound to ("test"/"live").
     */
    public function environment(): string
    {
        return $this->config['environment'] ?? 'test';
    }

    public function ipAddress(): string
    {
        return $this->config['ip_address'] ?? '127.0.0.1';
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(): array
    {
        return $this->post('authenticate', $this->config['auth_url'], [
            'UserName' => $this->config['username'],
            'Password' => $this->config['password'],
            'BookingMode' => $this->config['auth_mode'],
            'IPAddress' => $this->config['ip_address'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function search(array $payload): array
    {
        return $this->post('search', $this->config['search_url'], $payload);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $type, string $url, array $body): array
    {
        $startedAt = microtime(true);
        $status = null;
        $responseBody = null;
        $error = null;
        $successful = false;

        try {
            try {
                // Headers mirror the known-working integration: send a browser-like
                // Accept-Encoding and NO "Accept: application/json" (TBO's gateway can
                // hang on certain Accept values).
                $response = Http::connectTimeout($this->config['connect_timeout'] ?? 10)
                    ->timeout($this->config['timeout'])
                    ->withHeaders(['Accept-Encoding' => 'gzip, deflate, br'])
                    ->asJson()
                    ->post($url, $body);
            } catch (Throwable $e) {
                $error = $e->getMessage();

                throw new TboAirException('Could not reach TBO Air: '.$e->getMessage(), previous: $e);
            }

            $status = $response->status();
            $responseBody = $response->json();

            if ($response->failed()) {
                $error = "HTTP {$status}";

                throw new TboAirException("TBO Air responded with HTTP {$status}.");
            }

            $successful = true;

            return $responseBody ?? [];
        } finally {
            $this->record($type, $url, $body, $responseBody, $status, $successful, $error, $startedAt);
        }
    }

    /**
     * Persist the request/response. Logging must never break the API call.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    private function record(string $type, string $url, array $request, ?array $response, ?int $status, bool $successful, ?string $error, float $startedAt): void
    {
        if (! ($this->config['logging'] ?? true)) {
            return;
        }

        try {
            TboAirApiLog::create([
                'type' => $type,
                'environment' => $this->environment(),
                'endpoint' => $url,
                'status_code' => $status,
                'successful' => $successful,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'user_id' => Auth::id(),
                'request' => $this->sanitize($request),
                'response' => $response,
                'error' => $error,
            ]);
        } catch (Throwable) {
            // Swallow logging failures — they must not affect the search.
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function sanitize(array $request): array
    {
        if (array_key_exists('Password', $request)) {
            $request['Password'] = '********';
        }

        return $request;
    }
}
