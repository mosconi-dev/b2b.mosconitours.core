<?php

namespace App\Services\TboHotel\DTO;

/**
 * One room's occupancy. TBO prices per room, so a two-room booking is two of these
 * and the ages belong to the room the child sleeps in.
 */
readonly class PaxRoom
{
    /**
     * @param  array<int, int>  $childrenAges
     */
    public function __construct(
        public int $adults,
        public int $children = 0,
        public array $childrenAges = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'Adults' => $this->adults,
            'Children' => $this->children,
            // Never null: TBO wants an empty array for a room with no children.
            'ChildrenAges' => array_values(array_map('intval', $this->childrenAges)),
        ];
    }

    public function guests(): int
    {
        return $this->adults + $this->children;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            adults: (int) ($row['adults'] ?? 1),
            children: (int) ($row['children'] ?? 0),
            childrenAges: array_values(array_map('intval', $row['childrenAges'] ?? [])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['adults' => $this->adults, 'children' => $this->children, 'childrenAges' => $this->childrenAges];
    }
}
