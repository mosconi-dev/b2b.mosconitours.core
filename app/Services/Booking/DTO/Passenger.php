<?php

namespace App\Services\Booking\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A passenger on a booking. Passport fields are optional here and only enforced
 * by BookingService when the fare's FareQuote flags passport as mandatory.
 *
 * TBO's Book method also wants an address, city, country, mobile and email on
 * *every* passenger. Those are not per-passenger facts — asking an agent to type an
 * address for a two-year-old is nonsense — so they are collected once as the
 * booking's contact details and fanned onto each stored pax row by BookingService.
 * Only what genuinely varies per passenger lives here.
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
        // TBO requires exactly one lead passenger per booking; BookingService
        // guarantees one even when the caller flags none.
        public readonly bool $isLeadPax = false,
    ) {}

    public function isAdult(): bool
    {
        return strcasecmp($this->type, 'Adult') === 0;
    }

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
            isLeadPax: (bool) ($data['isLeadPax'] ?? false),
        );
    }

    /** A copy of this passenger with the lead flag set either way. */
    public function withLead(bool $isLeadPax): self
    {
        return new self(
            $this->type, $this->title, $this->firstName, $this->lastName, $this->gender,
            $this->dateOfBirth, $this->passportNo, $this->passportExpiry, $this->nationality,
            $this->baggage, $this->meal, isLeadPax: $isLeadPax,
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
            'isLeadPax' => $this->isLeadPax,
        ];
    }
}
