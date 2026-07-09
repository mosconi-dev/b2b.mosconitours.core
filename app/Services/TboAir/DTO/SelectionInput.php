<?php

namespace App\Services\TboAir\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Identifies one search result to price or pull rules for. Carries the search's
 * TraceId (valid ~15 min) and the chosen ResultIndex — the pair every downstream
 * TBO detail/booking call needs.
 */
class SelectionInput implements Arrayable
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $resultIndex,
    ) {}

    /**
     * @return array{traceId: string, resultIndex: string}
     */
    public function toArray(): array
    {
        return [
            'traceId' => $this->traceId,
            'resultIndex' => $this->resultIndex,
        ];
    }
}
