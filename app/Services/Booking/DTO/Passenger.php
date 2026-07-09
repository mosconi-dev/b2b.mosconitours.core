<?php

namespace App\Services\Booking\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A passenger on a booking. Passport fields are optional here and only enforced
 * by BookingService when the fare's FareQuote flags passport as mandatory.
 */
class Passenger implements Arrayable
{
    public function __construct(
        public readonly string $type,        // Adult | Child | Infant
        public readonly string $title,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $gender = null,
        public readonly ?string $dateOfBirth = null,
        public readonly ?string $passportNo = null,
        public readonly ?string $passportExpiry = null,
        public readonly ?string $nationality = null,
        public readonly ?string $baggage = null,  // selected SSR baggage code
        public readonly ?string $meal = null,     // selected SSR meal code
    ) {}

    public function isInfant(): bool
    {
        return strcasecmp($this->type, 'Infant') === 0;
    }

    public function hasPassport(): bool
    {
        return filled($this->passportNo) && filled($this->passportExpiry);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? 'Adult'),
            title: (string) ($data['title'] ?? ''),
            firstName: (string) ($data['firstName'] ?? ''),
            lastName: (string) ($data['lastName'] ?? ''),
            gender: $data['gender'] ?? null,
            dateOfBirth: $data['dateOfBirth'] ?? null,
            passportNo: $data['passportNo'] ?? null,
            passportExpiry: $data['passportExpiry'] ?? null,
            nationality: $data['nationality'] ?? null,
            baggage: $data['baggage'] ?? null,
            meal: $data['meal'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'gender' => $this->gender,
            'dateOfBirth' => $this->dateOfBirth,
            'passportNo' => $this->passportNo,
            'passportExpiry' => $this->passportExpiry,
            'nationality' => $this->nationality,
        ];
    }
}
