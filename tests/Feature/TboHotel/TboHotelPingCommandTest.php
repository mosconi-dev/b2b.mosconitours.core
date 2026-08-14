<?php

namespace Tests\Feature\TboHotel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TboHotelPingCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tbohotel.default' => 'test',
            'tbohotel.environments.test.credentials.username' => 'hotel-user',
            'tbohotel.environments.test.credentials.password' => 'hotel-pass',
            'tbohotel.environments.test.base_url' => self::BASE,
            'tbohotel.retry_delay' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    public function test_it_reports_the_url_it_called_and_what_came_back(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('countrylist'))]);

        $this->artisan('tbohotel:ping')
            ->expectsOutputToContain(self::BASE.'/CountryList')
            ->expectsOutputToContain('Countries: 3')
            ->assertSuccessful();
    }

    /**
     * CountryList is a GET and CityList a POST. A base URL that answers one and not
     * the other is a real possibility, so the ping can exercise both.
     */
    public function test_country_option_also_exercises_the_post_path(): void
    {
        Http::fake([
            self::BASE.'/CountryList' => Http::response($this->fixture('countrylist')),
            self::BASE.'/CityList' => Http::response($this->fixture('citylist')),
        ]);

        $this->artisan('tbohotel:ping --country=PH')
            ->expectsOutputToContain('Cities   : 4 in PH')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_it_fails_loudly_when_tbo_rejects_the_credentials(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('unauthorized'))]);

        $this->artisan('tbohotel:ping')
            ->expectsOutputToContain('Access Credentials is incorrect')
            ->expectsOutputToContain('whitelisted')
            ->assertFailed();
    }

    /**
     * The failure the base-URL disagreement would actually produce: nothing answers.
     * The hint has to name the other candidate, or the next step is guesswork.
     */
    public function test_a_timeout_points_at_the_other_candidate_base_url(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response('', 504)]);

        $this->artisan('tbohotel:ping')
            ->expectsOutputToContain('TBOHOTEL_TEST_BASE_URL')
            ->assertFailed();
    }

    public function test_it_refuses_to_call_with_no_credentials_configured(): void
    {
        config(['tbohotel.environments.test.credentials.username' => null]);
        Http::fake();

        $this->artisan('tbohotel:ping')
            ->expectsOutputToContain('No test credentials configured')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
