<?php

namespace App\Services\TboAir;

/**
 * Flattens the per-environment TBO config into the flat shape TboAirClient
 * consumes (auth_url, search_url, username, password, ip_address, …), tagged
 * with the resolved environment.
 */
class TboAirConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $env): array
    {
        $base = config('tboair');
        $envConfig = $base['environments'][$env] ?? [];

        return array_merge($base, [
            'environment' => $env,
            'username' => data_get($envConfig, 'credentials.username'),
            'password' => data_get($envConfig, 'credentials.password'),
            'ip_address' => data_get($envConfig, 'credentials.ip_address', '127.0.0.1'),
            'auth_url' => data_get($envConfig, 'endpoints.authentication'),
            'search_url' => data_get($envConfig, 'endpoints.search'),
            'endpoints' => data_get($envConfig, 'endpoints', []),
        ]);
    }
}
