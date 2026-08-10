<?php

namespace App\Services\TboAir\DTO;

use App\Enums\TboBookingStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The outcome of a Book, Ticket or GetBookingDetails call.
 *
 * All three answer the same questions — is there a PNR, what state is it in — so they
 * share one shape. `status` may be **null** when TBO sends nothing usable; that is
 * treated exactly like an ambiguous status, i.e. reconcile, never assume.
 *
 * The raw response is kept because a booking's supplier reply is evidence: it is what
 * gets attached to a TBO support ticket and what certification asks for.
 */
class BookingResult implements Arrayable
{
    public function __construct(
        public readonly ?string $pnr,
        public readonly ?string $bookingId,
        public readonly ?TboBookingStatus $status,
        public readonly bool $isPriceChanged = false,
        public readonly bool $isTimeChanged = false,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $data = self::unwrap($data);

        // Book/Ticket answer flat. GetBookingDetails nests, and the real key is
        // `Itinerary` — its doc page says `FlightItinerary`, which appears nowhere in
        // an actual response, so reading only that returned no PNR at all.
        $itinerary = data_get($data, 'Itinerary')
            ?? data_get($data, 'Response.FlightItinerary')
            ?? data_get($data, 'FlightItinerary');

        return new self(
            pnr: self::text(data_get($itinerary, 'PNR') ?? data_get($data, 'PNR') ?? data_get($data, 'Response.PNR')),
            bookingId: self::text(
                data_get($itinerary, 'BookingId')
                ?? data_get($data, 'BookingId')
                ?? data_get($data, 'Response.BookingId')
            ),
            status: TboBookingStatus::tryFromResponse(
                data_get($data, 'Status')
                ?? data_get($data, 'Response.Status')
                ?? data_get($itinerary, 'Status')
            ),
            isPriceChanged: (bool) (data_get($data, 'IsPriceChanged') ?? data_get($data, 'Response.IsPriceChanged', false)),
            isTimeChanged: (bool) (data_get($data, 'IsTimeChanged') ?? data_get($data, 'Response.IsTimeChanged', false)),
            raw: $data,
        );
    }

    /** A PNR exists, whatever the status says — there is something live at TBO. */
    public function hasPnr(): bool
    {
        return filled($this->pnr);
    }

    /**
     * Whether this outcome must be resolved by reading GetBookingDetails.
     *
     * True for every ambiguous status **and** for a missing one. It is also true when
     * TBO returned a PNR with no status at all: something exists and we do not know
     * what, which is precisely the case that must never be guessed.
     */
    public function needsReconciliation(): bool
    {
        return $this->status === null || $this->status->isAmbiguous();
    }

    /**
     * Book and Ticket wrap their answer in a **one-element JSON array**.
     *
     * Observed on a real Book against the test host: the whole body arrives as
     * `[{"PNR":"-","Errors":[…],"IsSuccess":false,…}]`. Reading it as an object finds
     * nothing at all — no status, no PNR, and worst of all no error message, so a
     * genuine supplier refusal surfaces as a blank fallback. Unwrap first.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function unwrap(array $data): array
    {
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return $data;
    }

    /**
     * TBO's own words for why this failed, if it gave any.
     *
     * This is how a supplier-side problem — insufficient agency funds, a fare that
     * has gone, a rejected passenger detail — actually reaches us: on the Book/Ticket
     * response itself, not from any pre-flight check. Worth surfacing verbatim.
     */
    public function message(): ?string
    {
        foreach ([
            'Errors.0.UserMessage',
            'Response.Error.ErrorMessage',
            'Error.ErrorMessage',
            'ErrorMessage',
            'Response.ErrorMessage',
        ] as $path) {
            $message = data_get($this->raw, $path);

            if (filled($message) && is_string($message)) {
                return trim($message);
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        // TBO uses "", "0" and "-" interchangeably with null for an absent
        // PNR/BookingId — the dash was observed on a real refused Book. Treating it as
        // a PNR would leave a booking claiming a reservation called "-".
        return in_array($value, ['', '0', '-'], true) ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pnr' => $this->pnr,
            'bookingId' => $this->bookingId,
            'status' => $this->status?->value,
            'statusLabel' => $this->status?->label(),
            'isPriceChanged' => $this->isPriceChanged,
            'isTimeChanged' => $this->isTimeChanged,
        ];
    }
}
