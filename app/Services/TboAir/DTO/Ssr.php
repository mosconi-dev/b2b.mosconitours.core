<?php

namespace App\Services\TboAir\DTO;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * Available ancillaries for a selected result (TBO GetSSR) — checked baggage and
 * meals. LCC fares only; non-LCC fares typically return none. Seats are not
 * modelled yet. The stored option carries the authoritative price used at booking.
 */
class Ssr implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, array{code: string, label: string, weight: int, price: float, currency: string, origin: string, destination: string}>  $baggage
     * @param  array<int, array{code: string, label: string, price: float, currency: string, origin: string, destination: string}>  $meals
     */
    public function __construct(
        public readonly ?string $traceId,
        public readonly string $resultIndex,
        public readonly array $baggage,
        public readonly array $meals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data, string $resultIndex): self
    {
        $baggage = self::flatten(data_get($data, 'Response.Baggage', data_get($data, 'Baggage', [])))
            ->filter(fn ($b): bool => filled(data_get($b, 'Code')))
            ->map(fn (array $b): array => [
                'code' => (string) data_get($b, 'Code'),
                'weight' => (int) data_get($b, 'Weight', 0),
                'label' => ((int) data_get($b, 'Weight', 0)).' kg',
                'price' => (float) data_get($b, 'Price', 0),
                'currency' => (string) data_get($b, 'Currency', 'PHP'),
                'origin' => (string) data_get($b, 'Origin', ''),
                'destination' => (string) data_get($b, 'Destination', ''),
            ])
            ->values()->all();

        $meals = self::flatten(data_get($data, 'Response.MealDynamic', data_get($data, 'Response.Meal', data_get($data, 'MealDynamic', []))))
            ->filter(fn ($m): bool => filled(data_get($m, 'Code')))
            ->map(fn (array $m): array => [
                'code' => (string) data_get($m, 'Code'),
                'label' => (string) data_get($m, 'AirlineDescription', data_get($m, 'Description', 'Meal')),
                'price' => (float) data_get($m, 'Price', 0),
                'currency' => (string) data_get($m, 'Currency', 'PHP'),
                'origin' => (string) data_get($m, 'Origin', ''),
                'destination' => (string) data_get($m, 'Destination', ''),
            ])
            ->values()->all();

        return new self(
            data_get($data, 'Response.TraceId', data_get($data, 'TraceId')),
            $resultIndex,
            $baggage,
            $meals,
        );
    }

    /**
     * The baggage option for a code, or null if it isn't offered (any more).
     *
     * @return array{code: string, label: string, weight: int, price: float, currency: string, origin: string, destination: string}|null
     */
    public function baggage(string $code): ?array
    {
        return collect($this->baggage)->firstWhere('code', $code);
    }

    /**
     * @return array{code: string, label: string, price: float, currency: string, origin: string, destination: string}|null
     */
    public function meal(string $code): ?array
    {
        return collect($this->meals)->firstWhere('code', $code);
    }

    /**
     * TBO nests these per segment (list-of-lists); collapse to a flat option list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function flatten(mixed $list): Collection
    {
        $items = collect(is_array($list) ? $list : []);

        if ($items->isNotEmpty() && is_array($items->first()) && array_is_list($items->first())) {
            return collect(Arr::collapse($items->all()));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'traceId' => $this->traceId,
            'resultIndex' => $this->resultIndex,
            'baggage' => $this->baggage,
            'meals' => $this->meals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
