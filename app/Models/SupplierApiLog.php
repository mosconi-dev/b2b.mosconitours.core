<?php

namespace App\Models;

use App\Enums\Supplier;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound call to a supplier: what we sent, what came back, how long it took.
 *
 * Every integration logs here. These rows are the first place a failing call is
 * read, and they are the evidence a supplier is shown when we disagree about what
 * their API did.
 */
class SupplierApiLog extends Model
{
    use BelongsToAgency;

    /**
     * Call types per supplier, for filtering. Not a constraint — an unlisted type
     * still logs; it just cannot be filtered on from a URL.
     *
     * @var array<string, array<int, string>>
     */
    public const TYPES = [
        Supplier::TboAir->value => [
            'authenticate', 'balance', 'search', 'farerule', 'farequote', 'ssr',
            'book', 'ticket', 'bookingdetails',
        ],
        Supplier::TboHotel->value => [
            'countrylist', 'citylist', 'hotelcodelist', 'tbohotelcodelist', 'hoteldetails',
            'search', 'prebook', 'book', 'bookingdetail', 'cancel', 'bookingdetailsbydate',
        ],
    ];

    protected $fillable = [
        'supplier',
        'type',
        'environment',
        'endpoint',
        'status_code',
        'successful',
        'duration_ms',
        'user_id',
        'agency_id',
        'request',
        'response',
        'error',
    ];

    /**
     * Matches the column default, so an unsaved row reads the same as a saved one.
     */
    protected $attributes = [
        'supplier' => Supplier::TboAir->value,
    ];

    protected $casts = [
        'supplier' => Supplier::class,
        'request' => 'array',
        'response' => 'array',
        'successful' => 'boolean',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every call type known to any supplier — the whitelist a `?type=` filter is
     * checked against.
     *
     * @return array<int, string>
     */
    public static function types(?Supplier $supplier = null): array
    {
        return $supplier === null
            ? array_values(array_unique(array_merge(...array_values(self::TYPES))))
            : (self::TYPES[$supplier->value] ?? []);
    }

    /**
     * A short, human-readable summary of the call.
     *
     * Only flight searches have anything better to say than their own type, so the
     * rest fall through to it rather than inventing a description.
     */
    public function summary(): string
    {
        if ($this->supplier === Supplier::TboAir && $this->type === 'search') {
            $segments = $this->request['Segments'] ?? [];

            if (! empty($segments)) {
                $first = $segments[0];
                $route = ($first['Origin'] ?? '?').' → '.($first['Destination'] ?? '?');
                $extra = count($segments) > 1 ? ' +'.(count($segments) - 1) : '';

                return $route.$extra;
            }
        }

        return ucfirst($this->type);
    }
}
