<?php

namespace App\Services\TboAir;

use App\Models\Booking;
use App\Services\Booking\Exceptions\BookingException;

/**
 * Builds the request body for TBO's Book and Ticket methods.
 *
 * **One builder serves both calls.** Ticket is not a second payload — it is this one
 * with a `PNR`: null for an LCC (where Ticket books and issues in one) and the Book
 * response's PNR for a non-LCC. That is how the live production system does it, and it
 * is simpler than TBO's method pages suggest. See
 * `_docs/tboair/04-live-reference-implementation.md` §2.
 *
 * The shape is echo-heavy by design: TBO wants the whole priced itinerary sent back,
 * with fares "exactly as received in the fare quote, without modifications". So the
 * segments and fare here are the verbatim objects TBO gave us, read from
 * `bookings.quote_raw`, with the two search-only fields it drops (`NoOfSeatAvailable`,
 * `ResultRecommendationType`) restored from their own columns.
 *
 * Nothing here talks to the network.
 */
class TboBookPayload
{
    /**
     * TBO's own constants. Named rather than inlined so the next reader can tell a
     * required protocol value from a business decision.
     */
    private const BOOKING_MODE = 5;

    private const SUPPLIER_GROUP_ID = 5;

    private const NULL_DATE = '0001-01-01T00:00:00';

    /**
     * @param  string|null  $pnr  set only when ticketing a held non-LCC PNR
     * @return array<string, mixed>
     */
    public static function for(Booking $booking, string $token, string $ipAddress, ?string $userAgent = null, ?string $pnr = null): array
    {
        $result = self::result($booking);
        $segments = self::segments($booking, $result);
        $passengers = self::passengers($booking, $result);
        $lead = self::leadName($booking);

        return [
            // TBO calls the search's ResultIndex "ResultId" here. Same identifier —
            // confirmed against the live system, see 04-live-reference §1.
            'ResultId' => $booking->result_index,
            'Itinerary' => [
                'FlightId' => 0,
                'IsManual' => false,
                'IssuancePCC' => null,
                'AgencySalesRepresentative' => 0,
                'IsHoldEligibleForLcc' => false,

                // The verbatim blocks. Segments_BE and Fare_BE are TBO's names.
                'Segments_BE' => $segments,
                'Passenger' => $passengers,
                'FareRules' => data_get($result, 'FareRules', []),
                'MiniFareRules' => data_get($result, 'MiniFareRules', [[]]),

                'PNR' => (string) ($pnr ?? ''),
                'InactivePNR' => null,
                'SplitPNR' => null,
                'PNRStatus' => 0,
                'BookingId' => null,
                'FailedBookingId' => 0,
                'ParentBookingId' => 0,

                'Origin' => self::endpointCode($segments, 'Origin', true),
                'Destination' => self::endpointCode($segments, 'Destination', false),
                'TravelDate' => self::firstDeparture($segments),
                'LastTicketDate' => data_get($result, 'LastTicketDate'),
                'LastVoidDate' => self::NULL_DATE,
                'CreatedOn' => now()->format('Y-m-d'),

                'FareType' => data_get($result, 'ResultFareType'),
                'ResultType' => $booking->result_type,
                'SearchType' => count($segments) > 0 ? self::journeyType($segments) : 1,
                'FlightBookingSource' => data_get($result, 'Source'),
                'TboAirBookingSourceId' => 0,
                'SupplierGroupId' => self::SUPPLIER_GROUP_ID,
                'SupplierCode' => null,

                'ValidatingAirline' => null,
                'ValidatingAirlineCode' => data_get($result, 'ValidatingAirline'),
                'AirlineCode' => null,
                'AirlineRemark' => data_get($result, 'AirlineRemark'),
                'IsDomestic' => null,
                'NonRefundable' => ! (bool) data_get($result, 'IsRefundable', false),
                'IsLcc' => (bool) $booking->is_lcc,
                'Ticketed' => false,

                'BookingMode' => self::BOOKING_MODE,
                'PaymentMode' => 0,
                'PaymentKey' => null,
                'ClientIP' => $ipAddress,
                'TokenId' => $token,
                'TrackingId' => $booking->trace_id,
                'AgentRefNo' => null,
                'OnBehalfOf' => 0,
                'EarnedLoyaltyPoints' => 0,
                'StaffRemarks' => null,
                'PricingKeyDetail' => null,
                'SSRData' => null,
                'TripId' => null,
                'TripName' => '',
                'isNewBooking' => false,
                'isFromBulkImport' => false,
                'isApplicableforNewWidgetWallet' => false,
                'isCancellationcoverAdded' => false,
                'callBackUrl' => '',
                'FoidDetails' => (object) [],
                'BookingOperations' => null,
                'UpsellOptionsList' => null,
                'HotelConfirmationNumber' => null,
                'HelpCenter' => null,
                'CustomizedFareType' => null,
                'IsVATApplicable' => true,
            ],

            'PNR' => (string) ($pnr ?? ''),
            'BookingId' => '',
            'CorporateCode' => null,
            'ConfirmPriceChangeTicket' => false,
            'IsGenerateTicketRequestFromQueues' => false,
            'SegmentAnalyticsToken' => '',
            'IPAddress' => $ipAddress,
            'TokenId' => $token,
            'TrackingId' => $booking->trace_id,
            'EndUserBrowserAgent' => $userAgent ?? '',
            'PointOfSale' => self::countryOf($segments, 'Origin'),
            'RequestOrigin' => self::countryNameOf($segments, 'Origin'),
            'UserData' => $lead,
            'WebServerIP' => null,
        ];
    }

    /**
     * The FareQuote result out of `quote_raw`.
     *
     * A booking with no raw quote cannot be booked at all — every field below comes
     * from it — so this fails loudly rather than sending TBO a hollow itinerary.
     *
     * @return array<string, mixed>
     */
    private static function result(Booking $booking): array
    {
        $raw = $booking->quote_raw ?? [];
        $result = data_get($raw, 'Response.Results', data_get($raw, 'Results'));

        if (is_array($result) && array_is_list($result)) {
            $result = $result[0] ?? null;
        }

        if (! is_array($result) || $result === []) {
            throw new BookingException(
                "Booking {$booking->reference} has no stored fare quote, so it cannot be sent to the airline."
            );
        }

        return $result;
    }

    /**
     * The itinerary segments, verbatim, with seat availability restored.
     *
     * TBO returns `NoOfSeatAvailable` on search segments and drops it from FareQuote,
     * so it is zipped back in from `seats_available` by position. A segment whose
     * availability was never captured is left without the key rather than given a 0 —
     * claiming zero seats on a flight we did not measure would be a lie about the one
     * field that can stop a sale.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private static function segments(Booking $booking, array $result): array
    {
        $flat = self::flatten(data_get($result, 'Segments', []));
        $seats = array_values((array) ($booking->seats_available ?? []));

        foreach ($flat as $i => $segment) {
            if (($seats[$i] ?? null) !== null) {
                $flat[$i]['NoOfSeatAvailable'] = (int) $seats[$i];
            }
        }

        return $flat;
    }

    /**
     * TBO nests Segments one list per direction, and sometimes sends them flat with a
     * TripIndicator. Either way Book wants one ordered list.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function flatten(mixed $segments): array
    {
        if (! is_array($segments)) {
            return [];
        }

        $flat = [];

        foreach ($segments as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // A group of legs, or a single leg (which carries its own Airline block).
            if (array_is_list($entry)) {
                foreach ($entry as $leg) {
                    if (is_array($leg)) {
                        $flat[] = $leg;
                    }
                }
            } else {
                $flat[] = $entry;
            }
        }

        return $flat;
    }

    /**
     * One Book passenger per stored pax row.
     *
     * Strings become TBO's integer enums here (`TboPassengerMapper`), and the address
     * and contact fields come off the row — `BookingService` fanned the booking's
     * shared contact block onto every passenger at persistence time, because TBO wants
     * them per passenger even though they do not vary.
     *
     * `Fare_BE` is the itinerary fare object sent whole on every passenger. TBO's docs
     * describe a per-passenger split instead, but the live production system sends the
     * whole object and tickets successfully, so that is what is mirrored here.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private static function passengers(Booking $booking, array $result): array
    {
        $fare = data_get($result, 'Fare', []);
        $rows = (array) ($booking->pax ?? []);

        // Which document a passenger carries follows the ROUTE; whether TBO also
        // insists on passport fields follows its own flags. The two are independent.
        $isDomestic = self::isDomestic($result);
        $passportRequired = (bool) data_get($result, 'IsPassportRequiredAtBook', false)
            || (bool) data_get($result, 'IsPassportRequiredAtTicket', false)
            || (bool) data_get($result, 'IsPassportFullDetailRequiredAtBook', false);

        if ($rows === []) {
            throw new BookingException("Booking {$booking->reference} has no passengers.");
        }

        // Keys are passed alongside, because IdDetails needs each passenger's real
        // position and two identical rows would otherwise resolve to the same one.
        return array_values(array_map(function (array $row, int $index) use ($fare, $isDomestic, $passportRequired): array {
            $isInfant = strcasecmp((string) ($row['type'] ?? ''), 'Infant') === 0;

            return [
                'Title' => TboPassengerMapper::title((string) ($row['title'] ?? '')),
                'FirstName' => (string) ($row['firstName'] ?? ''),
                'LastName' => (string) ($row['lastName'] ?? ''),
                'Type' => TboPassengerMapper::paxType((string) ($row['type'] ?? 'Adult')),
                'Gender' => TboPassengerMapper::gender($row['gender'] ?? null),
                'DateOfBirth' => self::dateTime($row['dateOfBirth'] ?? null),

                ...self::documents($row, $isDomestic, $passportRequired, $index),

                'Nationality' => [
                    'CountryCode' => $row['nationality'] ?? $row['countryCode'] ?? null,
                    'CountryName' => $row['countryName'] ?? null,
                ],
                'Country' => [
                    'CountryCode' => $row['countryCode'] ?? null,
                    'CountryName' => $row['countryName'] ?? null,
                ],
                'City' => [
                    'CountryCode' => $row['countryCode'] ?? null,
                    'CityCode' => null,
                    'CityName' => $row['city'] ?? null,
                ],

                'AddressLine1' => $row['addressLine1'] ?? null,
                'AddressLine2' => (string) ($row['addressLine2'] ?? ''),
                'Mobile1' => $row['mobile'] ?? null,
                'Mobile1CountryCode' => $row['mobileCountryCode'] ?? null,
                'Email' => $row['email'] ?? null,

                'Fare_BE' => $fare,
                'IsLeadPax' => (bool) ($row['isLeadPax'] ?? false),

                // Mandatory keys TBO expects to be present and null.
                'FFAirline' => null,
                'FFNumber' => null,

                // Arrays must never be null — empty instead. Infants may carry neither
                // baggage nor a seat, so theirs stay empty whatever was selected.
                'Baggage' => $isInfant ? [] : self::ssrCodes($row, 'baggage'),
                'MealDynamic' => $isInfant ? [] : self::ssrCodes($row, 'meal'),
                'SeatDynamic' => [],
            ];
        }, $rows, array_keys($rows)));
    }

    /**
     * The identity block TBO wants: `IdDetails`, plus the `Passport*` family.
     *
     * `IdType` follows the route — **1 international, 2 domestic** — because that is
     * what decides which document a traveller actually holds. A passport is asked for
     * on an international itinerary; on a domestic one any government ID will do, and
     * most people flying Manila to Cebu do not own a passport.
     *
     * When TBO's flags demand passport fields on a *domestic* fare — which they do,
     * even flagging only `AtTicket` and then rejecting the Book — the government ID is
     * sent in them, truncated to the 15 characters the field accepts. Without that a
     * domestic booking is refused with "Passport Number and Passport Expiry should not
     * be Empty" for a passport the passenger was never going to have.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function documents(array $row, bool $isDomestic, bool $passportRequired, int $index = 0): array
    {
        $number = $row['documentNumber'] ?? $row['passportNo'] ?? null;
        $expiry = self::dateTime($row['documentExpiry'] ?? $row['passportExpiry'] ?? null);
        $country = $row['documentIssueCountry'] ?? $row['nationality'] ?? null;
        $issued = self::dateTime($row['documentIssueDate'] ?? null);

        // A domestic ID often carries no expiry; TBO still wants one, and a date far
        // enough out cannot be mistaken for a real expiry.
        if ($isDomestic && $expiry === null && filled($number)) {
            $expiry = self::dateTime(now()->addYears(20)->format('Y-m-d'));
        }

        $passport = $isDomestic
            // Domestic: only populate the passport fields if TBO insists, and then
            // from the ID we actually hold.
            ? ($passportRequired && filled($number)
                ? ['no' => self::asPassportNumber($number), 'expiry' => $expiry, 'country' => $country, 'issued' => null]
                : ['no' => null, 'expiry' => null, 'country' => null, 'issued' => null])
            // International: the document IS the passport.
            : ['no' => $number, 'expiry' => $expiry, 'country' => $country, 'issued' => $issued];

        return [
            'PassportNo' => $passport['no'],
            'PassportExpiry' => $passport['expiry'],
            'PassportIssueCountryCode' => $passport['country'],
            'PassportIssueDate' => $passport['issued'],

            'PassengerIdType' => $isDomestic ? 2 : 1,
            'PassengerIdNo' => $number,
            'PassengerIdExpiry' => $expiry,
            'PassengerIdIssueCountryCode' => $country,
            'PassengerIdIssueDate' => $isDomestic ? ($issued ?? self::dateTime(now()->format('Y-m-d'))) : $issued,

            'IdDetails' => filled($number) ? [[
                'PaxId' => $index,
                'IdType' => $isDomestic ? 2 : 1,
                'IdNumber' => $number,
                'ExpiryDate' => $expiry,
                'IssuedCountryCode' => $country,
                'IssueDate' => $isDomestic ? ($issued ?? self::dateTime(now()->format('Y-m-d'))) : $issued,
            ]] : [],
        ];
    }

    /**
     * A government ID rendered as something TBO's passport field will accept.
     *
     * TBO rejects a Book with "Passport number must contain only letters and numbers",
     * and Philippine IDs are full of hyphens and spaces — a UMID reads
     * `UMID-1234-5678901`. Strip everything else first, *then* truncate, so the 15
     * characters that survive are 15 meaningful ones rather than a prefix padded with
     * punctuation.
     */
    private static function asPassportNumber(string $number): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9]/', '', $number), 0, 15);
    }

    /**
     * Every airport on the priced itinerary sits in the point-of-sale country.
     *
     * @param  array<string, mixed>  $result
     */
    private static function isDomestic(array $result): bool
    {
        $segments = self::flatten(data_get($result, 'Segments', []));

        if ($segments === []) {
            return false;
        }

        $home = strtoupper((string) config('tboair.point_of_sale', 'PH'));

        foreach ($segments as $segment) {
            foreach (['Origin', 'Destination'] as $side) {
                if (strtoupper((string) data_get($segment, "{$side}.Airport.CountryCode", '')) !== $home) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The passenger's selected SSR options, one entry per leg they bought it on.
     *
     * Sent as the **whole option** rather than a bare code, matching what the live
     * system sends: airline, flight number, WayType, price and route all go back as
     * TBO quoted them. A code alone is also ambiguous — TBO repeats the same one per
     * segment — so an entry without its route cannot say which leg it is for.
     *
     * Tolerates the pre-per-leg shape, where `ssr.baggage` was a single option object
     * rather than a list, so a booking stored before this still builds a payload.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>
     */
    private static function ssrCodes(array $row, string $kind): array
    {
        $selected = data_get($row, "ssr.{$kind}");

        if (! is_array($selected)) {
            return [];
        }

        // One option object, from before add-ons became per-leg.
        if (filled($selected['code'] ?? null)) {
            $selected = [$selected];
        }

        $entries = [];

        foreach ($selected as $option) {
            if (! is_array($option) || blank($option['code'] ?? null)) {
                continue;
            }

            $entry = [
                'Code' => $option['code'],
                'Description' => $option['description'] ?? '',
                'WayType' => $option['wayType'] ?? 0,
                'AirlineCode' => $option['airlineCode'] ?? '',
                'FlightNumber' => $option['flightNumber'] ?? '',
                'Currency' => $option['currency'] ?? 'PHP',
                'Price' => $option['price'] ?? 0,
                'Origin' => $option['origin'] ?? '',
                'Destination' => $option['destination'] ?? '',
            ];

            if ($kind === 'baggage') {
                $entry['Weight'] = $option['weight'] ?? 0;
            } else {
                $entry['AirlineDescription'] = $option['airlineDescription'] ?? '';
                $entry['Quantity'] = $option['quantity'] ?? 1;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * TBO wants dates as `Y-m-d\TH:i:s`. A date-only value gains midnight; anything
     * unparseable is passed through untouched rather than mangled into a wrong date.
     */
    private static function dateTime(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $time = strtotime((string) $value);

        return $time === false ? (string) $value : date('Y-m-d\TH:i:s', $time);
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private static function endpointCode(array $segments, string $side, bool $first): ?string
    {
        $segment = $first ? ($segments[0] ?? null) : ($segments[count($segments) - 1] ?? null);

        return $segment === null
            ? null
            : data_get($segment, "{$side}.Airport.AirportCode", data_get($segment, "{$side}.Airport.CityCode"));
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private static function countryOf(array $segments, string $side): ?string
    {
        return data_get($segments[0] ?? [], "{$side}.Airport.CountryCode");
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private static function countryNameOf(array $segments, string $side): ?string
    {
        return data_get($segments[0] ?? [], "{$side}.Airport.CountryName");
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private static function firstDeparture(array $segments): ?string
    {
        return self::dateTime(data_get($segments[0] ?? [], 'Origin.DepTime'));
    }

    /**
     * OneWay=1, Return=2. Read from the segments' TripIndicator rather than stored,
     * so it can never disagree with the itinerary actually being booked.
     *
     * @param  array<int, array<string, mixed>>  $segments
     */
    private static function journeyType(array $segments): int
    {
        $indicators = array_unique(array_map(
            fn (array $s): int => (int) data_get($s, 'TripIndicator', 1),
            $segments,
        ));

        return count($indicators) > 1 ? 2 : 1;
    }

    /**
     * The lead passenger's name, which TBO wants echoed as `UserData`.
     */
    private static function leadName(Booking $booking): string
    {
        $rows = (array) ($booking->pax ?? []);

        foreach ($rows as $row) {
            if ($row['isLeadPax'] ?? false) {
                return trim(($row['firstName'] ?? '').' '.($row['lastName'] ?? ''));
            }
        }

        $first = $rows[0] ?? [];

        return trim(($first['firstName'] ?? '').' '.($first['lastName'] ?? ''));
    }
}
