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
            ->filter(fn ($b): bool => self::isRealOption($b))
            ->map(fn (array $b): array => self::option($b, ((int) data_get($b, 'Weight', 0)).' kg', [
                'weight' => (int) data_get($b, 'Weight', 0),
            ]))
            ->values()->all();

        $meals = self::flatten(data_get($data, 'Response.MealDynamic', data_get($data, 'Response.Meal', data_get($data, 'MealDynamic', []))))
            ->filter(fn ($m): bool => self::isRealOption($m))
            ->map(fn (array $m): array => self::option($m, self::mealLabel($m), [
                'quantity' => (int) data_get($m, 'Quantity', 1),
                'airlineDescription' => (string) data_get($m, 'AirlineDescription', ''),
            ]))
            ->values()->all();

        return new self(
            data_get($data, 'Response.TraceId', data_get($data, 'TraceId')),
            $resultIndex,
            $baggage,
            $meals,
        );
    }

    /**
     * One SSR option, keeping every field Book needs echoed back.
     *
     * The live system sends the whole option object per entry — airline, flight
     * number, WayType, price, route — not just a code, so all of it is kept rather
     * than reduced to what the UI happens to render.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function option(array $raw, string $label, array $extra = []): array
    {
        $code = (string) data_get($raw, 'Code');
        $origin = (string) data_get($raw, 'Origin', '');
        $destination = (string) data_get($raw, 'Destination', '');

        return array_merge([
            // A code is NOT unique: TBO repeats the same one per segment at different
            // prices, so the leg has to be part of the identity or a selection on the
            // outbound silently resolves to the inbound's row.
            'key' => "{$code}|{$origin}|{$destination}",
            'code' => $code,
            'label' => $label,
            'price' => (float) data_get($raw, 'Price', 0),
            'currency' => (string) data_get($raw, 'Currency', 'PHP'),
            'origin' => $origin,
            'destination' => $destination,
            'wayType' => (int) data_get($raw, 'WayType', 0),
            'airlineCode' => (string) data_get($raw, 'AirlineCode', ''),
            'flightNumber' => (string) data_get($raw, 'FlightNumber', ''),
            'description' => data_get($raw, 'Description'),
        ], $extra);
    }

    /**
     * A meal's human name.
     *
     * `Description` is **not** text — TBO sends an integer meal-type code there, so
     * reading it as a label printed a bare "2" next to real dish names. Only
     * `AirlineDescription` carries words; without one, name it by its code.
     *
     * @param  array<string, mixed>  $meal
     */
    private static function mealLabel(array $meal): string
    {
        $name = trim((string) data_get($meal, 'AirlineDescription', ''));

        if ($name !== '') {
            return $name;
        }

        $code = trim((string) data_get($meal, 'Code'));

        return $code === '' ? 'Meal' : "Meal {$code}";
    }

    /**
     * Whether this is something a passenger can actually buy.
     *
     * TBO includes explicit `NoBaggage` / `NoMeal` rows — its way of spelling "none"
     * for a leg. We express that by sending no entry at all, so listing them would
     * put two different "none"s in front of the agent.
     *
     * @param  mixed  $option
     */
    private static function isRealOption($option): bool
    {
        $code = trim((string) data_get($option, 'Code'));

        return $code !== '' && ! in_array(strtolower($code), ['nobaggage', 'nomeal'], true);
    }

    /** Every leg these options cover, in the order TBO listed them. */
    public function legs(): array
    {
        return collect([...$this->baggage, ...$this->meals])
            ->map(fn (array $o): string => $o['origin'].'|'.$o['destination'])
            ->unique()->values()
            ->map(fn (string $leg): array => [
                'key' => $leg,
                'origin' => explode('|', $leg)[0],
                'destination' => explode('|', $leg)[1],
            ])
            ->all();
    }

    /**
     * The baggage option for a key (`code|origin|destination`), or null.
     *
     * Accepts a bare code too, so bookings saved before add-ons became per-leg still
     * resolve — they match the first leg offering that code.
     *
     * @return array<string, mixed>|null
     */
    public function baggage(string $key): ?array
    {
        return self::lookup($this->baggage, $key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function meal(string $key): ?array
    {
        return self::lookup($this->meals, $key);
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private static function lookup(array $options, string $key): ?array
    {
        return collect($options)->firstWhere('key', $key)
            ?? collect($options)->firstWhere('code', $key);
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
