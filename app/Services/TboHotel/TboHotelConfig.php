<?php

namespace App\Services\TboHotel;

/**
 * Flattens the per-environment TBO Holidays config into the shape TboHotelClient
 * consumes, tagged with the resolved environment.
 *
 * Simpler than its flight counterpart because the API is: one base URL per
 * environment, credentials sent as Basic Auth on every call, and no token anywhere.
 */
class TboHotelConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $env): array
    {
        $base = config('tbohotel');
        $envConfig = $base['environments'][$env] ?? [];

        return array_merge($base, [
            'environment' => $env,
            'username' => data_get($envConfig, 'credentials.username'),
            'password' => data_get($envConfig, 'credentials.password'),
            'base_url' => rtrim((string) data_get($envConfig, 'base_url'), '/'),
        ]);
    }
}
