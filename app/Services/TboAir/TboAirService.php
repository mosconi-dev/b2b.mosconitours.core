<?php

namespace App\Services\TboAir;

use App\Enums\TripType;
use App\Services\Settings\Settings;
use App\Services\TboAir\DTO\AgencyBalance;
use App\Services\TboAir\DTO\BookingResult;
use App\Services\TboAir\DTO\FareQuote;
use App\Services\TboAir\DTO\FareRule;
use App\Services\TboAir\DTO\FlightOffer;
use App\Services\TboAir\DTO\SearchInput;
use App\Services\TboAir\DTO\SelectionInput;
use App\Services\TboAir\DTO\Ssr;
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
     * @return array{offers: array<int, FlightOffer>, traceId: ?string, currency: string, resultType: ?int}
     */
    public function search(SearchInput $input): array
    {
        return $this->withReauth(fn (string $token): array => $this->doSearch($input, $token));
    }

    /**
     * Binding re-price of a selected result. May differ from the search fare.
     */
    public function fareQuote(SelectionInput $selection): FareQuote
    {
        return $this->withReauth(fn (string $token): FareQuote => $this->doFareQuote($selection, $token));
    }

    /**
     * Fare rules / cancellation policy for a selected result.
     */
    public function fareRule(SelectionInput $selection): FareRule
    {
        return $this->withReauth(fn (string $token): FareRule => $this->doFareRule($selection, $token));
    }

    /**
     * Available ancillaries (baggage / meals) for a selected result. LCC fares only.
     */
    public function ssr(SelectionInput $selection): Ssr
    {
        return $this->withReauth(fn (string $token): Ssr => $this->doSsr($selection, $token));
    }

    /**
     * The authoritative state of a booking at TBO, keyed on PNR.
     *
     * TBO requires this after every state-changing step, and it is the only way to
     * resolve an ambiguous Book/Ticket outcome. Never cached: the entire value of this
     * call is that it is current.
     */
    public function bookingDetails(string $pnr): BookingResult
    {
        return $this->withReauth(function (string $token) use ($pnr): BookingResult {
            $data = $this->client->bookingDetails($pnr, $token);
            $this->guardSession($data);

            if (data_get($data, 'Response.ResponseStatus') === 2 || data_get($data, 'IsSuccess') === false) {
                throw new TboAirException($this->firstError($data, "TBO returned no booking for PNR {$pnr}."));
            }

            return BookingResult::fromResponse($data);
        });
    }

    /**
     * Our agency balance with TBO — the funds ticketing actually spends.
     *
     * This is *not* the agency e-wallet: that is what our agencies prepay us, while
     * this is what we hold with the supplier. A Ticket can fail here while the
     * booking agency's own wallet is fully funded.
     *
     * Cached per environment, and deliberately not on a short TTL — the call
     * authenticates with credentials and hands back a fresh TokenId, and TBO's
     * published guide still claims one token per day, so polling it risks churning
     * the token a booking is mid-flight on. Pass $fresh only for a deliberate,
     * user-initiated refresh.
     *
     * No re-auth wrapper: there is no session token in this request to expire.
     */
    public function agencyBalance(bool $fresh = false): AgencyBalance
    {
        $key = $this->balanceCacheKey();

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::remember(
            $key,
            (int) config('tboair.balance_cache_ttl'),
            function (): array {
                $data = $this->client->agencyBalance();

                if (data_get($data, 'IsSuccess') !== true) {
                    throw new TboAirException($this->firstError($data, 'Could not read the TBO agency balance.'));
                }

                return AgencyBalance::fromResponse($data)->toArray();
            },
        );

        return new AgencyBalance(
            available: (string) ($cached['available'] ?? '0.00'),
            currency: (string) ($cached['currency'] ?? 'PHP'),
            localCurrency: $cached['localCurrency'] ?? null,
            localCurrencyRoe: $cached['localCurrencyRoe'] ?? null,
        );
    }

    /**
     * Whether our TBO balance covers an amount — the guard Phase 4.1 runs before
     * Ticket, so a booking fails on our side with a clear reason instead of being
     * rejected by TBO after the agency has already been charged.
     */
    public function hasFundsFor(string $amount): bool
    {
        return $this->agencyBalance()->covers($amount);
    }

    /**
     * Balance cache key, namespaced per environment — a test balance must never be
     * shown against live, exactly like the token cache.
     */
    public function balanceCacheKey(): string
    {
        return 'tboair.balance:'.$this->client->environment();
    }

    /**
     * Run a token-bearing call, re-authenticating exactly once if TBO reports an
     * expired session (ErrorCode 6) — the token may lapse before its cached TTL.
     *
     * @template T
     *
     * @param  callable(string): T  $call
     * @return T
     */
    private function withReauth(callable $call): mixed
    {
        try {
            return $call($this->token());
        } catch (TboAirException $e) {
            if ($e->isAuthError()) {
                Cache::forget($this->cacheKey());

                return $call($this->token());
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
     * @return array{offers: array<int, FlightOffer>, traceId: ?string, currency: string, resultType: ?int}
     */
    private function doSearch(SearchInput $input, string $token): array
    {
        $data = $this->client->search($this->buildPayload($input, $token));
        $this->guardSession($data);

        $offers = $this->transformer->transform($data);

        return [
            'offers' => $offers,
            'traceId' => data_get($data, 'Response.TraceId', data_get($data, 'TraceId')),
            // Search-only, like NoOfSeatAvailable: FareQuote drops it and Book wants
            // it back as ResultType. Observed as both 0 and 1, so it cannot be defaulted.
            'resultType' => ($rt = data_get($data, 'Response.ResultRecommendationType')) === null ? null : (int) $rt,
            'currency' => $offers[0]->price['currency'] ?? data_get($data, 'Agency.LocalCurrency', 'PHP'),
        ];
    }

    private function doFareQuote(SelectionInput $selection, string $token): FareQuote
    {
        $data = $this->client->fareQuote($this->detailPayload($selection, $token));
        $this->guardSession($data);

        return FareQuote::fromResponse($data);
    }

    private function doFareRule(SelectionInput $selection, string $token): FareRule
    {
        $data = $this->client->fareRule($this->detailPayload($selection, $token));
        $this->guardSession($data);

        return FareRule::fromResponse($data, $selection->resultIndex);
    }

    private function doSsr(SelectionInput $selection, string $token): Ssr
    {
        $data = $this->client->ssr($this->detailPayload($selection, $token));
        $this->guardSession($data);

        return Ssr::fromResponse($data, $selection->resultIndex);
    }

    /**
     * The common body every detail/booking call needs: the session token plus the
     * search's TraceId + the chosen ResultIndex.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(SelectionInput $selection, string $token): array
    {
        return [
            'EndUserIp' => $this->client->ipAddress(),
            'TokenId' => $token,
            'TraceId' => $selection->traceId,
            'ResultIndex' => $selection->resultIndex,
        ];
    }

    /**
     * ErrorCode 6 = invalid/expired session token. Throwing an auth error lets
     * withReauth() re-authenticate once and retry.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSession(array $data): void
    {
        $errorCode = data_get($data, 'Response.Error.ErrorCode', data_get($data, 'Error.ErrorCode'));

        if ((int) $errorCode === 6) {
            throw TboAirException::auth($this->firstError($data, 'TBO Air session expired.'));
        }
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
        // Checked in order of specificity. The bare `ErrorMessage` is the shape the
        // credential-authenticated calls (Authenticate, GetAvailableBalance) use —
        // they report errors flat rather than under an `Error` object.
        $candidates = [
            data_get($data, 'Errors.0.UserMessage'),
            data_get($data, 'Response.Error.ErrorMessage'),
            data_get($data, 'Error.ErrorMessage'),
            data_get($data, 'ErrorMessage'),
        ];

        foreach ($candidates as $message) {
            // Non-null but empty is the common success case; it must not win over the
            // fallback and leave the caller with a blank error.
            if (filled($message)) {
                return (string) $message;
            }
        }

        return $fallback;
    }
}
