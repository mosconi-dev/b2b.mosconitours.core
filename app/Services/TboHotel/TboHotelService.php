<?php

namespace App\Services\TboHotel;

use App\Enums\TboHotelStatus;
use App\Models\Hotel;
use App\Services\TboHotel\DTO\HotelOffer;
use App\Services\TboHotel\DTO\PreBookResult;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\DTO\SearchResult;
use App\Services\TboHotel\Exceptions\TboHotelException;
use Illuminate\Support\Arr;

/**
 * The application-facing TBO Holidays API.
 *
 * Thin by design: the client owns the transport and the status vocabulary, so what
 * lands here is normalisation — turning TBO's PascalCase envelopes into the shapes
 * the rest of the application uses, and nothing else.
 */
class TboHotelService
{
    public function __construct(private readonly TboHotelClient $client) {}

    public function environment(): string
    {
        return $this->client->environment();
    }

    public function url(string $method): string
    {
        return $this->client->url($method);
    }

    /**
     * Every country TBO sells hotels in.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function countries(): array
    {
        return $this->rows($this->client->countryList(), 'CountryList');
    }

    /**
     * Every city in one country. Codes are numeric but arrive as strings, and are
     * kept that way — they are identifiers, not quantities.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function cities(string $countryCode): array
    {
        return $this->rows($this->client->cityList(strtoupper(trim($countryCode))), 'CityList');
    }

    /**
     * Availability and prices for a stay.
     *
     * TBO's Search takes hotel codes, so a city search is this: look the city's
     * properties up locally, split them into chunks, and ask for all the chunks at
     * once. Roughly half of any list has no availability on a given date, so a
     * results page needs far more codes behind it than it shows.
     *
     * Ordering is by star rating and name — deterministic, so chunk boundaries are
     * stable across repeated searches and the cache lines up. It is deliberately not
     * a relevance order: price is unknown until TBO answers, and ranking by stars
     * beforehand would bias every result set upmarket.
     */
    public function search(SearchInput $input): SearchResult
    {
        $codes = $this->codesFor($input);

        if ($codes === []) {
            return new SearchResult([], '', 0, 0);
        }

        $chunks = array_chunk($codes, max(1, (int) config('tbohotel.search_chunk', 100)));
        $criteria = $input->toPayload();

        $responses = $this->client->searchPool(array_map(
            fn (array $chunk): array => $criteria + ['HotelCodes' => implode(',', $chunk)],
            $chunks,
        ));

        $raw = [];
        $failed = 0;
        $firstError = null;

        foreach ($responses as $response) {
            if ($response['error'] !== null) {
                // "No availability" is an answer, not a failure — a chunk of hotels
                // that are simply full must not be reported as an outage.
                if (! $response['error']->isNoAvailability()) {
                    $failed++;
                    $firstError ??= $response['error'];
                }

                continue;
            }

            foreach ((array) Arr::get($response['body'], 'HotelResult', []) as $hotel) {
                $raw[] = (array) $hotel;
            }
        }

        // Every chunk failing is not a partial result, it is a failed search, and the
        // agent is owed the reason. An empty page that cannot say whether the city is
        // full or the supplier is down is the worst of both.
        if ($firstError !== null && $failed === count($chunks)) {
            throw $firstError;
        }

        return $this->assemble($raw, count($codes), count($chunks), $failed);
    }

    /**
     * Re-price one rate and read the terms that will govern the booking.
     *
     * Always called server-side from the stored BookingCode, never from a price the
     * browser sent back: what the agent was shown is an input to the gate, not a
     * source of truth about money.
     *
     * Throws on 315 — an expired BookingCode means the search behind it is stale and
     * the agent needs a fresh one, not a retry of this call.
     *
     * @throws TboHotelException
     */
    public function preBook(string $bookingCode): PreBookResult
    {
        $body = $this->client->preBook($bookingCode);
        $result = PreBookResult::fromResponse($body);

        // Status 201 means different things to different methods. To Search it is an
        // answer — this chunk of hotels is full — so the client lets it through. To
        // PreBook it means the one rate we asked about is gone, which is a refusal,
        // and returning a zero-fare result would let it reach the wallet as free.
        if (! $result->isBookable()) {
            $code = (int) Arr::get($body, 'Status.Code', 0);

            throw TboHotelException::fromStatus(
                TboHotelStatus::tryFrom($code),
                $code,
                (string) Arr::get($body, 'Status.Description', '') ?: 'That rate is no longer available.',
            );
        }

        return $result;
    }

    /**
     * Send a Book exactly as assembled, and hand back what TBO said.
     *
     * Raw on purpose. Every other method here normalises, but this one's caller has to
     * distinguish "TBO refused" from "TBO did not answer", and normalising would blur
     * the two into one failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws TboHotelException
     */
    public function bookRaw(array $payload): array
    {
        return $this->client->book($payload);
    }

    /**
     * Read a booking back from TBO — the authoritative account of whether it exists.
     *
     * @return array<string, mixed>
     *
     * @throws TboHotelException
     */
    public function bookingDetail(string $reference, bool $isConfirmationNumber = false): array
    {
        return $this->client->bookingDetail($reference, $isConfirmationNumber);
    }

    /**
     * Release a reservation. Raw for the same reason Book is: the caller has to tell a
     * refusal (479) from silence, and the two mean opposite things for the money.
     *
     * @return array<string, mixed>
     *
     * @throws TboHotelException
     */
    public function cancel(string $confirmationNumber): array
    {
        return $this->client->cancel($confirmationNumber);
    }

    /**
     * The hotel codes a search covers: one property, or a whole city's.
     *
     * @return array<int, string>
     */
    private function codesFor(SearchInput $input): array
    {
        if (! $input->isCitySearch()) {
            return [$input->locationCode];
        }

        return Hotel::query()
            ->where('city_code', $input->locationCode)
            ->orderByDesc('rating')
            ->orderBy('name')
            ->pluck('code')
            ->all();
    }

    /**
     * Join TBO's rates to what we know about the properties.
     *
     * Search returns a hotel code and prices and nothing else — no name, no address,
     * no photograph — so a result with no catalogue row behind it cannot be rendered
     * and is dropped rather than shown as "Hotel 1104180".
     *
     * @param  array<int, array<string, mixed>>  $raw
     */
    private function assemble(array $raw, int $searched, int $chunks, int $failed): SearchResult
    {
        $hotels = Hotel::query()
            ->whereIn('code', array_column($raw, 'HotelCode'))
            ->get()
            ->keyBy('code');

        $offers = [];
        $currency = '';

        foreach ($raw as $row) {
            $hotel = $hotels->get((string) ($row['HotelCode'] ?? ''));

            if ($hotel === null) {
                continue;
            }

            $offer = HotelOffer::fromResponse($row, $hotel);
            $currency = $currency !== '' ? $currency : $offer->currency;
            $offers[] = $offer;
        }

        usort($offers, fn (HotelOffer $a, HotelOffer $b): int => $a->lowestFare() <=> $b->lowestFare());

        return new SearchResult($offers, $currency, $searched, $chunks, $failed);
    }

    /**
     * Every hotel TBO holds for one city, normalised.
     *
     * `cityCode` is echoed onto each row rather than read from the response: TBO
     * answers Alcoy's hotels with `CityName: "Cebu City"`, and Search is driven off
     * the code we asked with, so that is the one that must be stored.
     *
     * @return array<int, array<string, mixed>>
     */
    public function hotels(string $cityCode): array
    {
        $rows = Arr::get($this->client->hotelCodeList($cityCode), 'Hotels', []);
        $hotels = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = (array) $row;
            $code = trim((string) Arr::get($row, 'HotelCode', ''));

            if ($code === '') {
                continue;
            }

            $hotels[] = [
                'code' => $code,
                'city_code' => $cityCode,
                'country_code' => strtoupper(trim((string) Arr::get($row, 'CountryCode', ''))),
                'name' => trim((string) Arr::get($row, 'HotelName', '')),
                'address' => $this->text(Arr::get($row, 'Address')),
                'rating' => $this->rating(Arr::get($row, 'HotelRating')),
                'latitude' => $this->coordinate(Arr::get($row, 'Latitude')),
                'longitude' => $this->coordinate(Arr::get($row, 'Longitude')),
            ];
        }

        return $hotels;
    }

    /**
     * Descriptions, images and facilities for a batch of hotels, keyed by code.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<string, mixed>>
     */
    public function details(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = Arr::get($this->client->hotelDetails($codes), 'HotelDetails', []);
        $details = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = (array) $row;
            $code = trim((string) Arr::get($row, 'HotelCode', ''));

            if ($code === '') {
                continue;
            }

            // HotelDetails gives coordinates as one "lat|lng" string where the code
            // list gives two fields. Same data, two shapes, one column pair.
            [$latitude, $longitude] = $this->splitMap(Arr::get($row, 'Map'));

            $details[$code] = array_filter([
                'description' => $this->text(Arr::get($row, 'Description')),
                'facilities' => $this->strings(Arr::get($row, 'HotelFacilities')),
                'attractions' => $this->strings(Arr::get($row, 'Attractions')),
                'images' => $this->strings(Arr::get($row, 'Images')),
                'phone' => $this->text(Arr::get($row, 'PhoneNumber')),
                'email' => $this->text(Arr::get($row, 'Email')),
                'website' => $this->text(Arr::get($row, 'HotelWebsiteUrl')),
                'pin_code' => $this->text(Arr::get($row, 'PinCode')),
                'checkin_time' => $this->text(Arr::get($row, 'CheckInTime')),
                'checkout_time' => $this->text(Arr::get($row, 'CheckOutTime')),
                'rating' => $this->rating(Arr::get($row, 'HotelRating')),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ], fn ($value): bool => $value !== null && $value !== []);
        }

        return $details;
    }

    /**
     * TBO reports the same star rating two ways: "ThreeStar" from the code list and
     * 3 from HotelDetails. Both land on the integer.
     */
    private function rating(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $rating = (int) $value;

            return $rating >= 1 && $rating <= 5 ? $rating : null;
        }

        return match (strtolower(trim((string) $value))) {
            'onestar' => 1,
            'twostar' => 2,
            'threestar' => 3,
            'fourstar' => 4,
            'fivestar' => 5,
            default => null,
        };
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function splitMap(mixed $map): array
    {
        $parts = explode('|', (string) $map);

        return [
            $this->coordinate($parts[0] ?? null),
            $this->coordinate($parts[1] ?? null),
        ];
    }

    private function coordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * `Attractions` is documented as a String and arrives as an array; a hotel with
     * none sends an empty one. Both shapes reduce to a clean list.
     *
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $value,
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * CountryList and CityList answer with the same `{ Code, Name }` shape under
     * different keys. Rows missing either half are dropped rather than stored as a
     * half-record nothing can resolve.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array{code: string, name: string}>
     */
    private function rows(array $body, string $key): array
    {
        $rows = Arr::get($body, $key, []);

        if (! is_array($rows)) {
            return [];
        }

        $mapped = [];

        foreach ($rows as $row) {
            $code = trim((string) Arr::get((array) $row, 'Code', ''));
            $name = trim((string) Arr::get((array) $row, 'Name', ''));

            if ($code === '' || $name === '') {
                continue;
            }

            $mapped[] = ['code' => $code, 'name' => $name];
        }

        return $mapped;
    }
}
