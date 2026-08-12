<?php

namespace App\Services\TboAir\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Our agency balance with TBO — the pot ticketing draws down.
 *
 * Not to be confused with the agency e-wallet (`App\Services\Wallet`), which is what
 * our agencies prepay *us*. The two can disagree: a Ticket can fail for insufficient
 * TBO funds while the booking agency's own wallet is fully funded.
 *
 * `available` is kept as a decimal **string** so comparisons go through bcmath, the
 * same as every other money value in the app — a float would round the one number
 * standing between us and a failed ticket.
 */
class AgencyBalance implements Arrayable
{
    public function __construct(
        public readonly string $available,
        public readonly string $currency,
        public readonly ?string $localCurrency = null,
        public readonly ?string $localCurrencyRoe = null,
    ) {}

    /**
     * Read a balance out of a TBO response.
     *
     * Two shapes are accepted because TBO ships both: GetAvailableBalance documents
     * the fields flat, while the Authenticate response nests the same block under
     * `Agency` — so an Authenticate reply can be read for a free (if stale) balance.
     *
     * `TotalAailableLimit` is not a typo here: that is how TBO spells it in the
     * Authenticate response, missing the "v". Both spellings are accepted rather than
     * betting on which one this endpoint uses today.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $limit = self::firstPresent($data, [
            'TotalAvailableLimit', 'TotalAailableLimit',
            'Agency.TotalAvailableLimit', 'Agency.TotalAailableLimit',
        ]);

        $currency = self::firstPresent($data, ['Currency', 'Agency.Currency']);
        $localCurrency = self::firstPresent($data, ['LocalCurrency', 'Agency.LocalCurrency']);
        $roe = self::firstPresent($data, ['LocalCurrencyROE', 'Agency.LocalCurrencyROE']);

        return new self(
            available: self::decimal($limit),
            // TBO has been seen to send Currency as an empty string while LocalCurrency
            // carries the real one, so fall through rather than show a blank.
            currency: (string) ($currency ?: ($localCurrency ?: 'PHP')),
            localCurrency: $localCurrency === null ? null : (string) $localCurrency,
            localCurrencyRoe: $roe === null ? null : (string) $roe,
        );
    }

    /**
     * The first of several candidate keys that is actually present and non-empty.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private static function firstPresent(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether the balance covers an amount. Both sides are decimal strings; bccomp
     * returns 0 when they are equal, which still counts as covered.
     */
    public function covers(string $amount): bool
    {
        return bccomp($this->available, self::decimal($amount), 2) >= 0;
    }

    /**
     * TBO sends the limit as a string, and has been seen to use thousands separators.
     * bcmath reads "1,500.00" as 1, so strip anything that is not part of the number
     * before it gets near an arithmetic call.
     */
    private static function decimal(mixed $value): string
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $clean === '' || ! is_numeric($clean)
            ? '0.00'
            : number_format((float) $clean, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'currency' => $this->currency,
            'localCurrency' => $this->localCurrency,
            'localCurrencyRoe' => $this->localCurrencyRoe,
        ];
    }
}
