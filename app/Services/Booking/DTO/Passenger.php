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
        // The identity document TBO's IdDetails block carries. Which one is asked for
        // depends on the route, not on TBO's passport flags: a passport
        // internationally, any government ID domestically — see TboBookPayload.
        public readonly ?string $documentNumber = null,
        public readonly ?string $documentExpiry = null,
        public readonly ?string $documentIssueCountry = null,
        public readonly ?string $documentIssueDate = null,
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

    public function isChild(): bool
    {
        return strcasecmp($this->type, 'Child') === 0;
    }

    /** For error messages: which passenger the agent has to go and fix. */
    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /** Enough of an identity document to satisfy TBO: a number and an expiry. */
    public function hasDocument(): bool
    {
        return filled($this->documentNumber) && filled($this->documentExpiry);
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
            // `passportNo`/`passportExpiry` are the pre-document names, still present
            // on bookings stored before this and on any older client payload.
            documentNumber: $data['documentNumber'] ?? $data['passportNo'] ?? null,
            documentExpiry: $data['documentExpiry'] ?? $data['passportExpiry'] ?? null,
            documentIssueCountry: $data['documentIssueCountry'] ?? null,
            documentIssueDate: $data['documentIssueDate'] ?? null,
            nationality: $data['nationality'] ?? null,
            baggage: $data['baggage'] ?? null,
            meal: $data['meal'] ?? null,
            isLeadPax: (bool) ($data['isLeadPax'] ?? false),
        );
    }

    /** A copy of this passenger with the lead flag set either way. */
    public function withLead(bool $isLeadPax): self
    {
        // Named throughout: a positional copy silently shifts every field the day one
        // is inserted, and this constructor keeps growing.
        return new self(
            type: $this->type,
            title: $this->title,
            firstName: $this->firstName,
            lastName: $this->lastName,
            gender: $this->gender,
            dateOfBirth: $this->dateOfBirth,
            documentNumber: $this->documentNumber,
            documentExpiry: $this->documentExpiry,
            documentIssueCountry: $this->documentIssueCountry,
            documentIssueDate: $this->documentIssueDate,
            nationality: $this->nationality,
            baggage: $this->baggage,
            meal: $this->meal,
            isLeadPax: $isLeadPax,
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
            'documentNumber' => $this->documentNumber,
            'documentExpiry' => $this->documentExpiry,
            'documentIssueCountry' => $this->documentIssueCountry,
            'documentIssueDate' => $this->documentIssueDate,
            'nationality' => $this->nationality,
            'isLeadPax' => $this->isLeadPax,
        ];
    }
}
