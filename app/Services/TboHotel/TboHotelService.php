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
