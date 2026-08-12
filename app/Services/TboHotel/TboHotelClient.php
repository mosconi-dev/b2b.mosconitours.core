<?php

namespace App\Services\TboHotel;

use App\Enums\Supplier;
use App\Enums\TboHotelStatus;
use App\Models\SupplierApiLog;
use App\Services\TboHotel\Exceptions\TboHotelException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP boundary for TBO Holidays.
 *
 * Every call is Basic Auth against `{base_url}/{Method}`. There is no session to
 * establish, nothing to cache, and nothing to re-authenticate — which removes the
 * whole class of failure the flight client spends most of its code on.
 *
 * What it does own: the per-method timeout, the throttle retry, translating
 * `Status.Code` into an exception, and logging every attempt.
 */
class TboHotelClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function environment(): string
    {
        return $this->config['environment'] ?? 'test';
    }

    public function baseUrl(): string
    {
        return (string) ($this->config['base_url'] ?? '');
    }

    /**
     * The full URL a method resolves to. Public so `tbohotel:ping` can show it —
     * the base URL is an open question and the answer is easier to see than describe.
     */
    public function url(string $method): string
    {
        $path = $this->config['methods'][$method] ?? $method;

        return $this->baseUrl().'/'.$path;
    }

    /**
     * Every TBO country code and name. GET, no body (§11).
     *
     * @return array<string, mixed>
     */
    public function countryList(): array
    {
        return $this->call('countrylist', retryable: true);
    }

    /**
     * Every city in one country (§12).
     *
     * @return array<string, mixed>
     */
    public function cityList(string $countryCode): array
    {
        return $this->call('citylist', ['CountryCode' => $countryCode], retryable: true);
    }

    /**
     * Every hotel TBO holds for one city (§16).
     *
     * `IsDetailedResponse` is documented to add descriptions and images and measurably
     * does not — the response is byte-identical either way — so it is not sent.
     *
     * @return array<string, mixed>
     */
    public function hotelCodeList(string $cityCode): array
    {
        return $this->call('tbohotelcodelist', ['CityCode' => $cityCode], retryable: true);
    }

    /**
     * Full detail for up to ~100 hotels at once (§14).
     *
     * Batched deliberately: one call per hotel would put an eight-hour crawl between
     * us and a catalogue. A code TBO does not recognise is simply absent from the
     * response rather than failing the batch.
     *
     * @param  array<int, string>  $codes
     * @return array<string, mixed>
     */
    public function hotelDetails(array $codes, string $language = 'EN'): array
    {
        return $this->call('hoteldetails', [
            // TBO's own spelling, from the §14.1.1 sample. The parameter table calls
            // it "Hotel Code"; the sample is what the API answers to.
            'Hotelcodes' => implode(',', $codes),
            'Language' => $language,
        ], retryable: true);
    }

    /**
     * Issue one call and return its decoded body.
     *
     * **`retryable` defaults to false.** Retrying is safe only for reads; a Book or
     * Cancel that appears to have been rejected cannot be proven not to have landed,
     * and the cost of being wrong is a double reservation. Callers opt in.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws TboHotelException
     */
    protected function call(string $method, array $body = [], bool $retryable = false): array
    {
        $attempts = $retryable ? max(1, (int) ($this->config['retries'] ?? 0) + 1) : 1;
        $delay = max(0, (int) ($this->config['retry_delay'] ?? 0));

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($method, $body);
            } catch (TboHotelException $e) {
                // Only throttling is worth repeating, and only while attempts remain.
                if (! $e->isThrottled() || $attempt >= $attempts) {
                    throw $e;
                }

                usleep($delay * 1000 * $attempt); // linear back-off: 1s, 2s, …
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws TboHotelException
     */
    private function attempt(string $method, array $body): array
    {
        $url = $this->url($method);
        $verb = strtoupper((string) ($this->config['verbs'][$method] ?? 'POST'));
        $startedAt = microtime(true);

        $httpStatus = null;
        $responseBody = null;
        $successful = false;
        $error = null;

        try {
            try {
                $request = Http::withBasicAuth((string) $this->config['username'], (string) $this->config['password'])
                    ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                    ->timeout($this->timeoutFor($method))
                    // Mirrors the known-working integration: TBO's gateway is happier
                    // with a browser-like Accept-Encoding than with defaults.
                    ->withHeaders(['Accept-Encoding' => 'gzip, deflate, br'])
                    ->asJson();

                /** @var Response $response */
                $response = $verb === 'GET'
                    ? $request->get($url, $body)
                    : $request->post($url, $body);
            } catch (Throwable $e) {
                $error = $e->getMessage();

                throw TboHotelException::transport(
                    'Could not reach TBO Holidays: '.$e->getMessage(),
                    timeout: $e instanceof ConnectionException,
                    previous: $e,
                );
            }

            $httpStatus = $response->status();

            [$responseBody, $successful, $error] = $this->interpret($response);

            return $responseBody;
        } finally {
            $this->record($method, $url, $body, $responseBody, $httpStatus, $successful, $error, $startedAt);
        }
    }

    /**
     * Turn one response into a body, or throw. Shared by the sequential path and the
     * pool, because deciding what "worked" means is exactly where a second copy of
     * this logic would drift.
     *
     * @return array{0: array<string, mixed>, 1: bool, 2: string|null} body, successful, error
     *
     * @throws TboHotelException
     */
    private function interpret(Response $response): array
    {
        $httpStatus = $response->status();

        if ($response->failed()) {
            throw TboHotelException::transport(
                "TBO Holidays responded with HTTP {$httpStatus}.",
                httpStatus: $httpStatus,
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = $this->statusOf($body);

        // Not every method answers with a Status envelope — hotelcodelist (§13)
        // returns a bare `{ "HotelCodes": [...] }`. A missing envelope on a 2xx
        // with a body is a success, not a silent failure.
        if ($status === null) {
            if ($body === []) {
                throw TboHotelException::transport('TBO Holidays returned an empty response.', httpStatus: $httpStatus);
            }

            return [$body, true, null];
        }

        if (! $status->isUsable()) {
            throw TboHotelException::fromStatus(
                $status,
                (int) Arr::get($body, 'Status.Code'),
                Arr::get($body, 'Status.Description'),
            );
        }

        return [$body, $status->isSuccess(), null];
    }

    /**
     * Run many Search calls at once.
     *
     * A city is one call per hundred hotels — Manila is twenty-eight of them — and
     * three seconds each in sequence is a minute and a half of an agent's time. The
     * pool is bounded because TBO has never told us our QPS limit.
     *
     * Failures are **returned, not thrown**: one unreachable chunk should cost that
     * chunk's hotels, not the whole search. The caller decides what to say about it.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array{body: array<string, mixed>|null, error: TboHotelException|null}>
     */
    public function searchPool(array $payloads): array
    {
        $url = $this->url('search');
        $timeout = $this->timeoutFor('search');
        $connect = (int) ($this->config['connect_timeout'] ?? 10);
        $results = [];

        foreach (array_chunk($payloads, max(1, (int) ($this->config['search_concurrency'] ?? 6)), true) as $wave) {
            $startedAt = microtime(true);

            $responses = Http::pool(function ($pool) use ($wave, $url, $timeout, $connect): array {
                $requests = [];

                foreach ($wave as $key => $payload) {
                    $requests[] = $pool->as((string) $key)
                        ->withBasicAuth((string) $this->config['username'], (string) $this->config['password'])
                        ->connectTimeout($connect)
                        ->timeout($timeout)
                        ->withHeaders(['Accept-Encoding' => 'gzip, deflate, br'])
                        ->asJson()
                        ->post($url, $payload);
                }

                return $requests;
            });

            foreach ($wave as $key => $payload) {
                $results[$key] = $this->settle($responses[$key] ?? null, $url, $payload, $startedAt);
            }
        }

        return $results;
    }

    /**
     * One pooled response, interpreted and logged like any other call.
     *
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>|null, error: TboHotelException|null}
     */
    private function settle(mixed $response, string $url, array $payload, float $startedAt): array
    {
        $body = null;
        $successful = false;
        $error = null;
        $httpStatus = null;
        $failure = null;

        try {
            if ($response instanceof Throwable) {
                throw TboHotelException::transport(
                    'Could not reach TBO Holidays: '.$response->getMessage(),
                    timeout: $response instanceof ConnectionException,
                    previous: $response,
                );
            }

            if (! $response instanceof Response) {
                throw TboHotelException::transport('TBO Holidays returned nothing for this chunk.');
            }

            $httpStatus = $response->status();
            [$body, $successful, $error] = $this->interpret($response);
        } catch (TboHotelException $e) {
            $failure = $e;
            $error = $e->getMessage();
            $body = null;
        }

        $this->record('search', $url, $payload, $body, $httpStatus, $successful, $error, $startedAt);

        return ['body' => $body, 'error' => $failure];
    }

    /**
     * TBO's own status, or null when the response carries no Status envelope.
     *
     * An unrecognised code is deliberately *not* null — it must not be mistaken for
     * "no envelope" and waved through as a success.
     *
     * @param  array<string, mixed>  $body
     */
    private function statusOf(array $body): ?TboHotelStatus
    {
        if (! Arr::has($body, 'Status.Code')) {
            return null;
        }

        $code = (int) Arr::get($body, 'Status.Code');

        return TboHotelStatus::tryFrom($code) ?? TboHotelStatus::UnexpectedError;
    }

    private function timeoutFor(string $method): int
    {
        $timeouts = $this->config['timeouts'] ?? [];

        return (int) ($timeouts[$method] ?? $timeouts['default'] ?? 60);
    }

    /**
     * Persist the request/response. Logging must never break the call.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    private function record(string $method, string $url, array $request, ?array $response, ?int $httpStatus, bool $successful, ?string $error, float $startedAt): void
    {
        if (! ($this->config['logging'] ?? true)) {
            return;
        }

        try {
            SupplierApiLog::create([
                'supplier' => Supplier::TboHotel,
                'type' => $method,
                'environment' => $this->environment(),
                'endpoint' => $url,
                'status_code' => $httpStatus,
                'successful' => $successful,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'user_id' => Auth::id(),
                'agency_id' => Auth::user()?->agency_id,
                'request' => $this->sanitize($request),
                'response' => $response,
                'error' => $error,
            ]);
        } catch (Throwable) {
            // Swallow logging failures — they must not affect the call.
        }
    }

    /**
     * Credentials travel in the Authorization header, which is never logged, so the
     * request body holds nothing secret today.
     *
     * The card fields are masked anyway. Card payment is out of scope, but the day
     * someone adds `PaymentMode: NewCard` a PAN would otherwise land in a database
     * table that half the office can read — and that is not a mistake you get to
     * notice before it matters.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function sanitize(array $request): array
    {
        foreach (['CardNumber', 'CvvNumber', 'CardExpirationMonth', 'CardExpirationYear'] as $field) {
            if (Arr::has($request, "PaymentInfo.{$field}")) {
                Arr::set($request, "PaymentInfo.{$field}", '********');
            }
        }

        return $request;
    }
}
