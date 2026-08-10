<?php

namespace App\Console\Commands;

use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirConfig;
use App\Services\TboAir\TboAirService;
use Illuminate\Console\Command;

class TboAirBalanceCommand extends Command
{
    protected $signature = 'tboair:balance {--fresh : Ignore the cached balance and read it from TBO}';

    protected $description = 'Show OUR agency balance with TBO — the funds ticketing spends, not the agency e-wallet.';

    public function handle(TboAirService $service): int
    {
        $env = $service->environment();
        $config = TboAirConfig::for($env);

        $this->line('Env      : '.$env);
        $this->line('URL      : '.data_get($config, 'endpoints.agency_balance'));
        $this->line('User     : '.$config['username']);
        $this->newLine();

        $start = microtime(true);

        try {
            $balance = $service->agencyBalance(fresh: (bool) $this->option('fresh'));
        } catch (TboAirException $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->error("FAILED after {$ms}ms: ".$e->getMessage());
            $this->newLine();
            $this->line('This call authenticates with credentials rather than a TokenId, so a failure');
            $this->line('points at the credentials, the URL, or IP whitelisting — not an expired session.');
            $this->line('Inspect the logged attempt with: php artisan tboair:logs --type=balance');

            return self::FAILURE;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->info("OK in {$ms}ms");
        $this->line('Available: '.$balance->currency.' '.number_format((float) $balance->available, 2));

        if ($balance->localCurrency !== null && $balance->localCurrency !== $balance->currency) {
            $this->line('Local    : '.$balance->localCurrency.' (ROE '.$balance->localCurrencyRoe.')');
        }

        $this->newLine();
        $this->line('This is what TBO deducts on ticketing. The agency e-wallet at /wallet is a');
        $this->line('separate balance — what our agencies prepay us — and the two can disagree.');

        return self::SUCCESS;
    }
}
