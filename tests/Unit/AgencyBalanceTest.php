<?php

namespace Tests\Unit;

use App\Services\TboAir\DTO\AgencyBalance;
use PHPUnit\Framework\TestCase;

class AgencyBalanceTest extends TestCase
{
    public function test_it_reads_the_documented_response_fields(): void
    {
        $balance = AgencyBalance::fromResponse([
            'Currency' => 'PHP',
            'TotalAvailableLimit' => '125430.50',
            'LocalCurrency' => 'USD',
            'LocalCurrencyROE' => '56.25',
            'IsSuccess' => true,
        ]);

        $this->assertSame('125430.50', $balance->available);
        $this->assertSame('PHP', $balance->currency);
        $this->assertSame('USD', $balance->localCurrency);
        $this->assertSame('56.25', $balance->localCurrencyRoe);
    }

    /**
     * bcmath reads "1,500.00" as 1, which would understate the balance by three
     * orders of magnitude and block tickets we can afford.
     */
    public function test_it_strips_thousands_separators_before_any_arithmetic(): void
    {
        $balance = AgencyBalance::fromResponse(['TotalAvailableLimit' => '1,500.00']);

        $this->assertSame('1500.00', $balance->available);
        $this->assertTrue($balance->covers('1200.00'));
    }

    public function test_it_falls_back_to_zero_for_an_unusable_limit(): void
    {
        foreach ([null, '', 'N/A'] as $value) {
            $this->assertSame('0.00', AgencyBalance::fromResponse(['TotalAvailableLimit' => $value])->available);
        }
    }

    /**
     * The Authenticate response carries the same block nested under `Agency`, and
     * spells the limit without the "v". Both are TBO's, not ours.
     */
    public function test_it_reads_the_nested_misspelled_shape_from_authenticate(): void
    {
        $balance = AgencyBalance::fromResponse([
            'Agency' => [
                'TotalAailableLimit' => 4200.0,
                'Currency' => '',
                'LocalCurrency' => 'PHP',
                'LocalCurrencyROE' => 55.8019665161,
            ],
            'IsSuccess' => true,
        ]);

        $this->assertSame('4200.00', $balance->available);
        // Currency is blank in that response, so LocalCurrency stands in.
        $this->assertSame('PHP', $balance->currency);
    }

    public function test_it_defaults_the_currency_when_tbo_omits_it(): void
    {
        $this->assertSame('PHP', AgencyBalance::fromResponse(['TotalAvailableLimit' => '10'])->currency);
    }

    public function test_covers_compares_as_decimals_not_floats(): void
    {
        $balance = AgencyBalance::fromResponse(['TotalAvailableLimit' => '6400.00']);

        $this->assertTrue($balance->covers('6399.99'));
        $this->assertTrue($balance->covers('6400.00'), 'an exact match is covered');
        $this->assertFalse($balance->covers('6400.01'));
    }

    public function test_a_negative_balance_covers_nothing(): void
    {
        $balance = AgencyBalance::fromResponse(['TotalAvailableLimit' => '-250.00']);

        $this->assertSame('-250.00', $balance->available);
        $this->assertFalse($balance->covers('0.01'));
    }
}
