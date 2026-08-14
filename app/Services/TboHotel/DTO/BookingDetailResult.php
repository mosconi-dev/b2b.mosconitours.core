<?php

namespace App\Services\TboHotel\DTO;

use Illuminate\Support\Arr;

/**
 * What TBO says a booking is — the authoritative read (§10).
 *
 * One place that knows how to read a BookingDetail response, because two callers need
 * the same answer for opposite reasons: the reconcile job asking "did the Book I never
 * got an answer to actually land", and the refresh asking "is this reservation still
 * standing". Two readings of the same payload would eventually disagree, and the
 * disagreement would be settled in favour of whichever ran last.
 *
 * The classification is deliberately narrow. TBO has six spellings of a cancellation in
 * progress and none of them is an ending, so anything not plainly settled is reported
 * as unknown rather than guessed at — acting on a half-answer is how a real reservation
 * gets refunded, or a dead one charged for.
 */
class BookingDetailResult
{
    /** Their word for it, ours for nothing: kept verbatim for support. */
    private const CONFIRMED = ['confirmed', 'vouchered', 'success'];

    private const CANCELLED = ['cancelled', 'canceled', 'cancelledandrefundawaited'];

    private const FAILED = ['failed', 'rejected'];

    /** In progress at TBO, and not an ending in either direction (§17). */
    private const CANCELLING = ['cancellationinprogress', 'cancelpending', 'cxlrequestsenttohotel'];

    /**
     * @param  array<int, array{name: ?string, status: string}>  $rooms
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $confirmationNumber,
        public readonly ?string $hotelConfirmationNumber,
        public readonly ?string $invoiceNumber,
        public readonly ?bool $vouchered,
        public readonly array $rooms,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $response  the whole reply, not just BookingDetail
     */
    public static function fromResponse(array $response): self
    {
        $detail = Arr::get($response, 'BookingDetail');
        $detail = is_array($detail) ? $detail : [];

        return new self(
            status: trim((string) Arr::get($detail, 'BookingStatus', '')),
            confirmationNumber: self::text($detail, 'ConfirmationNumber'),
            // Absent from the response entirely until TBO issues it, which it only does
            // within thirty days of check-in (§10.1). Absent is normal, not missing.
            hotelConfirmationNumber: self::text($detail, 'HotelConfirmationNumber'),
            invoiceNumber: self::text($detail, 'InvoiceNumber'),
            vouchered: self::flag($detail, 'VoucherStatus'),
            rooms: self::rooms($detail),
            raw: $detail,
        );
    }

    /**
     * Whether TBO accounted for the booking at all. An empty status is the shape a
     * reference it has never heard of comes back in.
     */
    public function isKnown(): bool
    {
        return $this->status !== '';
    }

    public function isConfirmed(): bool
    {
        return $this->matches(self::CONFIRMED) && filled($this->confirmationNumber);
    }

    public function isCancelled(): bool
    {
        return $this->matches(self::CANCELLED);
    }

    public function isFailed(): bool
    {
        return $this->matches(self::FAILED);
    }

    /** Requested, not yet honoured. Neither cancelled nor safely still standing. */
    public function isCancelling(): bool
    {
        return $this->matches(self::CANCELLING);
    }

    /**
     * Whether this is an ending we can act on. Everything else — a state we do not
     * recognise, a cancellation still travelling, an empty answer — is a question to
     * ask again rather than a fact to write down.
     */
    public function isSettled(): bool
    {
        return $this->isConfirmed() || $this->isCancelled() || $this->isFailed();
    }

    /**
     * @param  array<int, string>  $states
     */
    private function matches(array $states): bool
    {
        return in_array(strtolower(preg_replace('/[^a-z]/i', '', $this->status) ?? ''), $states, true);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private static function text(array $detail, string $key): ?string
    {
        return trim((string) Arr::get($detail, $key, '')) ?: null;
    }

    /**
     * TBO writes VoucherStatus as the strings "True"/"False" as often as as a boolean,
     * and a missing one is unknown rather than false.
     *
     * @param  array<string, mixed>  $detail
     */
    private static function flag(array $detail, string $key): ?bool
    {
        $value = Arr::get($detail, $key);

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * Per-room status, added 24 Apr 2026 — a multi-room booking can be cancelled a room
     * at a time, which our single booking status cannot express.
     *
     * @param  array<string, mixed>  $detail
     * @return array<int, array{name: ?string, status: string}>
     */
    private static function rooms(array $detail): array
    {
        $rooms = [];

        foreach ((array) Arr::get($detail, 'Rooms', []) as $room) {
            if (! is_array($room)) {
                continue;
            }

            $names = array_filter(array_map('trim', (array) Arr::get($room, 'Name', [])));

            $rooms[] = [
                'name' => $names === [] ? null : implode(' + ', $names),
                'status' => trim((string) Arr::get($room, 'Status', '')),
            ];
        }

        return $rooms;
    }
}
