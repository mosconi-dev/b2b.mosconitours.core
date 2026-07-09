<?php

namespace App\Services\TboAir\DTO;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Fare rules / cancellation policy for a selected result (TBO FareRule) — one entry
 * per origin→destination leg, with the rule text as provided by the airline/GDS.
 */
class FareRule implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, array{origin: string, destination: string, airline: string, detail: string}>  $rules
     */
    public function __construct(
        public readonly ?string $traceId,
        public readonly string $resultIndex,
        public readonly array $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data, string $resultIndex): self
    {
        $rules = array_map(fn (array $r): array => [
            'origin' => (string) data_get($r, 'Origin', ''),
            'destination' => (string) data_get($r, 'Destination', ''),
            'airline' => (string) data_get($r, 'Airline', ''),
            'detail' => self::cleanRuleText((string) data_get($r, 'FareRuleDetail', data_get($r, 'FareRuleDetails', ''))),
        ], array_values((array) data_get($data, 'Response.FareRules', data_get($data, 'FareRules', []))));

        return new self(
            traceId: data_get($data, 'Response.TraceId', data_get($data, 'TraceId')),
            resultIndex: $resultIndex,
            rules: $rules,
        );
    }

    /**
     * TBO returns fare rules as HTML. Turn line-break-ish tags into newlines, drop the
     * rest of the markup, decode entities, and tidy whitespace — so the client can render
     * it as plain text (no raw <br/> leaking through, no provider HTML to trust/execute).
     */
    private static function cleanRuleText(string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
        $text = preg_replace('/<\s*\/?\s*(?:p|div|li|tr)\b[^>]*>/i', "\n", $text);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);

        // Trim each line and collapse runs of blank lines.
        $lines = array_map('trim', explode("\n", $text));
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim((string) $text);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'traceId' => $this->traceId,
            'resultIndex' => $this->resultIndex,
            'rules' => $this->rules,
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
