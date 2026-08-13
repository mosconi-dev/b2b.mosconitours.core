<?php

namespace App\Services\TboHotel\DTO;

/**
 * One person sleeping in one room.
 *
 * The titles are **not** the flight set. TBO Hotel accepts `Mr`, `Mrs` and `Ms` only —
 * no `Miss`, no `Mstr` — and `Type` is sent as the words `Adult` / `Child`, where the
 * air API uses numeric codes. Two suppliers, one vendor, different vocabularies.
 *
 * `roomIndex` is which room this guest occupies, zero-based, because Book groups
 * `CustomerDetails` per room and a guest in the wrong group is a guest in the wrong
 * bed. Contact details are not per guest — they sit on the booking, since TBO takes a
 * single `EmailId` and `PhoneNumber` for the whole reservation.
 */
readonly class Guest
{
    public const TITLES = ['Mr', 'Mrs', 'Ms'];

    public const ADULT = 'Adult';

    public const CHILD = 'Child';

    public function __construct(
        public string $title,
        public string $firstName,
        public string $lastName,
        public string $type = self::ADULT,
        public int $roomIndex = 0,
        public bool $isLead = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: self::title((string) ($data['title'] ?? '')),
            firstName: trim((string) ($data['firstName'] ?? '')),
            lastName: trim((string) ($data['lastName'] ?? '')),
            type: ($data['type'] ?? self::ADULT) === self::CHILD ? self::CHILD : self::ADULT,
            roomIndex: max(0, (int) ($data['roomIndex'] ?? 0)),
            isLead: (bool) ($data['isLead'] ?? false),
        );
    }

    public function isAdult(): bool
    {
        return $this->type === self::ADULT;
    }

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function withLead(bool $isLead): self
    {
        return new self($this->title, $this->firstName, $this->lastName, $this->type, $this->roomIndex, $isLead);
    }

    /**
     * The shape Book's `CustomerNames` wants.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return [
            'Title' => $this->title,
            'FirstName' => $this->firstName,
            'LastName' => $this->lastName,
            'Type' => $this->type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'type' => $this->type,
            'roomIndex' => $this->roomIndex,
            'isLead' => $this->isLead,
        ];
    }

    /**
     * A title TBO will accept. Anything unrecognised becomes `Mr` rather than being
     * passed through: the booking is refused otherwise, and a wrong honorific is a
     * smaller problem than a failed reservation.
     */
    private static function title(string $title): string
    {
        $title = ucfirst(strtolower(trim($title)));

        return in_array($title, self::TITLES, true) ? $title : 'Mr';
    }
}
