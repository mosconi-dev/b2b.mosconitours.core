<?php

namespace App\Services\Booking\Concerns;

/**
 * Who issued a printed document, and how the traveller reaches them.
 *
 * The agency is the customer's counterparty — when a flight moves or a hotel loses a
 * reservation, they call the agency, not us and not the supplier. So the details are
 * resolved rather than printed blank: an agency that has not uploaded a logo still gets
 * a branded document, and one with no contact email falls back to the agent who
 * actually made the booking.
 *
 * Shared by the e-ticket and the hotel voucher because the masthead is the same
 * masthead. Two copies would drift, and the one that drifted would be the one a guest
 * is holding at a desk.
 *
 * Expects a readonly `$booking` on the using class.
 */
trait IssuedByAgency
{
    /**
     * @return array{name: string, logo: string, email: ?string, phone: ?string, address: ?string}
     */
    public function issuer(): array
    {
        $agency = $this->booking->agency;

        return [
            'name' => $agency?->name ?: config('app.name'),
            'logo' => $agency?->logoUrl() ?: asset('favicon.png'),
            'email' => $agency?->contact_email ?: $this->booking->user?->email,
            'phone' => $agency?->contact_phone ?: null,
            'address' => $agency?->address ?: null,
        ];
    }
}
