<?php

namespace App\Console\Commands;

use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TboAirAuthCommand extends Command
{
    protected $signature = 'tboair:auth {--fresh : Ignore the cached token and force a new authentication}';

    protected $description = 'Test TBO Air authentication (isolates the Authenticate call) and show the token or error.';

    public function handle(TboAirService $service): int
    {
        $this->line('Auth URL : '.config('tboair.auth_url'));
        $this->line('User     : '.config('tboair.username'));
        $this->line('IP       : '.config('tboair.ip_address'));
        $this->newLine();

        if ($this->option('fresh')) {
            Cache::forget(config('tboair.cache_key'));
        }

        $start = microtime(true);

        try {
            $token = $service->token();
        } catch (TboAirException $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->error("FAILED after {$ms}ms: ".$e->getMessage());
            $this->newLine();
            $this->line('Tip: a cURL timeout means the host accepted no response — verify the Authenticate URL');
            $this->line('with TBO and that your server IP is whitelisted, then set TBOAIR_AUTH_URL in .env.');
            $this->line('Inspect the logged attempt with: php artisan tboair:logs --type=authenticate');

            return self::FAILURE;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->info("OK in {$ms}ms");
        $this->line('TokenId  : '.$token);

        return self::SUCCESS;
    }
}
