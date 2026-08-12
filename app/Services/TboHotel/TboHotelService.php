<?php

namespace App\Services\TboHotel;

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
