<?php

namespace App\Services\Pricing;

/**
 * Spreading a selling price across the per-passenger-type rows of a fare breakdown.
 *
 * Two screens need this and they must agree: the wizard, pricing a live FareQuote, and
 * a stored booking being read back months later. A second implementation would drift,
 * and the drift would show up as rows that do not sum to the total beside them.
 *
 * Allocated in proportion to each row's share of net, with the LAST row absorbing the
 * rounding remainder, so the rows always reconcile to the total exactly.
 *
 * A FareBreakdown row is the GROUP total for a passenger type — a row of three adults
 * already holds the fare for all three — so nothing here multiplies by `count`.
 */
final class FareAllocation
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  supplier rows, each with baseFare/tax/count
     * @return array<int, array<string, mixed>> the same rows plus `amount` and `amountTotal`
     */
    public static function allocate(array $rows, Money $net, Money $sell): array
    {
        if ($rows === []) {
            return $rows;
        }

        $allocated = Money::zero();
        $last = array_key_last($rows);

        foreach ($rows as $i => $row) {
            $count = max(1, (int) ($row['count'] ?? 1));
            $rowNet = Money::of($row['baseFare'] ?? 0)->plus(Money::of($row['tax'] ?? 0));

            if ($i === $last) {
                // Whatever is left, so the rows reconcile to the total exactly.
                $rowSell = $sell->minus($allocated);
            } else {
                $rowSell = $net->isZero()
                    ? Money::zero()
                    : $rowNet->times(bcdiv((string) $sell, (string) $net, 6));
                $allocated = $allocated->plus($rowSell);
            }

            $rows[$i]['amount'] = $rowSell->times(bcdiv('1', (string) $count, 6))->toFloat();
            $rows[$i]['amountTotal'] = $rowSell->toFloat();
        }

        return array_values($rows);
    }

    /**
     * Spread a selling total across rows in proportion to their net prices.
     *
     * The same shape as `allocate()` — last row absorbs the rounding remainder — but
     * over a bare list of amounts rather than fare-breakdown rows, because the add-on
     * lines on a document are not fare rows and have no passenger-type to carry.
     *
     * @param  array<int, string|float|int>  $nets
     * @return array<int, Money>
     */
    public static function spread(array $nets, Money $sell): array
    {
        if ($nets === []) {
            return [];
        }

        $total = Money::zero();

        foreach ($nets as $net) {
            $total = $total->plus(Money::of($net));
        }

        $out = [];
        $allocated = Money::zero();
        $last = array_key_last($nets);

        foreach ($nets as $i => $net) {
            if ($i === $last) {
                $out[$i] = $sell->minus($allocated);

                continue;
            }

            $out[$i] = $total->isZero()
                ? Money::zero()
                : Money::of($net)->times(bcdiv((string) $sell, (string) $total, 6));

            $allocated = $allocated->plus($out[$i]);
        }

        return $out;
    }

    /**
     * What the rows themselves add up to — the supplier's fare for the trip.
     *
     * Used as the denominator when allocating a stored booking, rather than the
     * booking's `net_amount`: that figure also carries ancillaries, and the rows do not,
     * so dividing by it would shrink every line by the cost of the bags.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function netOf(array $rows): Money
    {
        $net = Money::zero();

        foreach ($rows as $row) {
            $net = $net->plus(Money::of($row['baseFare'] ?? 0))->plus(Money::of($row['tax'] ?? 0));
        }

        return $net;
    }

    /**
     * The supplier's own components, kept only for someone entitled to the net.
     *
     * `baseFare` and `tax` are the supplier's figures and they sum to the net, so a row
     * carrying them hands over our cost in one addition.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function redact(array $rows): array
    {
        foreach ($rows as $i => $row) {
            unset($rows[$i]['baseFare'], $rows[$i]['tax']);
        }

        return $rows;
    }
}
