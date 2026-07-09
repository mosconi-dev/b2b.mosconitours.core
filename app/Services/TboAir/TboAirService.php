<?php

namespace App\Services\TboAir;

use App\Enums\TripType;
use App\Services\Settings\Settings;
use App\Services\TboAir\DTO\FlightOffer;
use App\Services\TboAir\DTO\SearchInput;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Support\Airports;
use Illuminate\Support\Facades\Cache;

class TboAirService
{
    public function __construct(
        private readonly TboAirClient $client,
        private readonly FlightResultTransformer $transformer,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{offers: array<int, FlightOffer>, traceId: ?string, currency: string}
     */
    public function search(SearchInput $input): array
    {
        try {
            return $this->doSearch($input, $this->token());
        } catch (TboAirException $e) {
            // Token may have expired before its cached TTL — re-auth once.
            if ($e->isAuthError()) {
                Cache::forget($this->cacheKey());

                return $this->doSearch($input, $this->token());
            }

            throw $e;
        }
    }

    public function token(): string
    {
        return Cache::remember(
            $this->cacheKey(),
            $this->tokenTtl(),
            fn (): string => $this->authenticate(),
        );
    }

    public function environment(): string
    {
        return $this->client->environment();
    }

    /**
     * Token cache lifetime (seconds) for the active environment. Admin-overridable
     * per environment (Settings), falling back to config. Bounded when saved.
     */
    public function tokenTtl(): int
    {
        $override = $this->settings->get('tbo.token_ttl.'.$this->client->environment());

        return (int) ($override ?: config('tboair.token_ttl'));
    }

    /**
     * Token cache key, namespaced per environment so test and live never collide.
     */
    public function cacheKey(): string
    {
        return config('tboair.cache_key').':'.$this->client->environment();
    }

    private function authenticate(): string
    {
        $data = $this->client->authenticate();

        $token = data_get($data, 'TokenId');

        if (data_get($data, 'IsSuccess') !== true || empty($token)) {
            throw TboAirException::auth($this->firstError($data, 'TBO Air authentication failed.'));
        }

        return (string) $token;
    }

    /**
     * @return array{offers: array<int, FlightOffer>, traceId: ?string, currency: string}
     */
    private function doSearch(SearchInput $input, string $token): array
    {
        $data = $this->client->search($this->buildPayload($input, $token));

        $errorCode = data_get($data, 'Response.Error.ErrorCode', data_get($data, 'Error.ErrorCode'));

        // ErrorCode 6 = invalid/expired session token.
        if ((int) $errorCode === 6) {
            throw TboAirException::auth($this->firstError($data, 'TBO Air session expired.'));
        }

        $offers = $this->transformer->transform($data);

        return [
            'offers' => $offers,
            'traceId' => data_get($data, 'Response.TraceId', data_get($data, 'TraceId')),
            'currency' => $offers[0]->price['currency'] ?? data_get($data, 'Agency.LocalCurrency', 'PHP'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(SearchInput $input, string $token): array
    {
        $effective = $this->effectiveSegments($input);

        $segments = array_map(fn (array $s): array => [
            'Origin' => $s['origin'],
            'Destination' => $s['destination'],
            'PreferredDepartureTime' => $this->departureDateTime($s['departure']),
            'PreferredArrivalTime' => $this->departureDateTime($s['departure']),
            'FlightCabinClass' => (string) $input->cabin->tboCode(),
        ], $effective);

        return [
            'AdultCount' => $input->adults,
            'ChildCount' => $input->children,
            'InfantCount' => $input->infants,
            'IsDomestic' => $this->isDomestic($effective),
            'BookingMode' => config('tboair.booking_mode'),
            'DirectFlight' => false,
            'OneStopFlight' => false,
            'JourneyType' => $input->tripType->journeyType(),
            'EndUserIp' => $this->client->ipAddress(),
            'TokenId' => $token,
            'PreferredAirlines' => [],
            'Sources' => [],
            'Segments' => $segments,
            'ResultFareType' => 0,
        ];
    }

    /**
     * TBO expects a full datetime (yyyy-MM-ddTHH:mm:ss), not a bare date.
     */
    private function departureDateTime(string $date): string
    {
        return substr($date, 0, 10).'T00:00:00';
    }

    /**
     * Domestic when every segment stays within the Philippines.
     *
     * @param  array<int, array{origin: string, destination: string, departure: string}>  $segments
     */
    private function isDomestic(array $segments): bool
    {
        $domestic = array_column(
            array_filter(Airports::all(), fn (array $a): bool => ($a['country'] ?? '') === 'Philippines'),
            'code'
        );

        foreach ($segments as $s) {
            if (! in_array($s['origin'], $domestic, true) || ! in_array($s['destination'], $domestic, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * For round-trips, append the return leg so JourneyType=2 carries two segments.
     *
     * @return array<int, array{origin: string, destination: string, departure: string}>
     */
    private function effectiveSegments(SearchInput $input): array
    {
        $segments = $input->segments;

        if ($input->tripType === TripType::Round && $input->returnDate) {
            $first = $segments[0];
            $segments[] = [
                'origin' => $first['destination'],
                'destination' => $first['origin'],
                'departure' => $input->returnDate,
            ];
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function firstError(array $data, string $fallback): string
    {
        return data_get($data, 'Errors.0.UserMessage')
            ?? data_get($data, 'Response.Error.ErrorMessage')
            ?? data_get($data, 'Error.ErrorMessage')
            ?? $fallback;
    }
}
