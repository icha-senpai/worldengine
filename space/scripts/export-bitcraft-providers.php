<?php

use Illuminate\Contracts\Console\Kernel;

// Read-only ICHAA config export. This private file must never become a Vite asset.
require __DIR__.'/../../ichaa/vendor/autoload.php';
$app = require __DIR__.'/../../ichaa/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$providers = [];
foreach (['bitjita', 'bitjuice', 'bitcraft_relay'] as $source) {
    $config = config('services.'.$source);
    $name = $source === 'bitcraft_relay' ? 'relay' : $source;
    $mapped = [];
    foreach (['enabled' => 'enabled', 'base_url' => 'baseUrl', 'timeout' => 'timeout', 'requests_per_minute' => 'requestsPerMinute', 'user_requests_per_minute' => 'callerRequestsPerMinute', 'stale_cache_seconds' => 'staleSeconds', 'failure_cooldown_seconds' => 'failureCooldown', 'app_identifier' => 'appIdentifier', 'identity' => 'identity', 'token' => 'token'] as $key => $target) {
        if (isset($config[$key])) {
            $mapped[$target] = $config[$key];
        }
    }
    $ttl = [];
    $keys = ['regions' => 'regions', 'claims' => 'claims', 'empires' => 'empires', 'items' => 'items', 'market' => 'market', 'market_orders' => 'itemOrders', 'claim_market_listings' => 'claimListings', 'claim_details' => 'claim', 'claim_buildings' => 'claimBuildings', 'stalls' => 'stalls', 'players' => 'players', 'player' => 'player', 'player_inventories' => 'inventories', 'inventories' => 'inventories', 'player_passive_crafts' => 'passive', 'passive_crafts' => 'passive', 'crafts' => 'crafts'];
    foreach ($keys as $key => $target) {
        if (isset($config[$key.'_cache_seconds'])) {
            $ttl[$target] = $config[$key.'_cache_seconds'];
        }
    }
    foreach (['items' => ['cargo', 'item', 'cargoItem'], 'empires' => ['empireClaims'], 'itemOrders' => ['cargoOrders']] as $key => $aliases) {
        if (isset($ttl[$key])) {
            foreach ($aliases as $alias) {
                $ttl[$alias] = $ttl[$key];
            }
        }
    }
    if ($name === 'relay') {
        foreach (['players', 'player', 'inventories', 'crafts', 'claim'] as $key) {
            $ttl[$key] = $config['cache_seconds'];
        }
    }
    $mapped['ttl'] = $ttl;
    if (isset($config['pool_concurrency'])) {
        $mapped['poolConcurrency'] = $config['pool_concurrency'];
    }
    $providers[$name] = $mapped;
}
$directory = __DIR__.'/../.runtime';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
file_put_contents($directory.'/provider-config.json', json_encode($providers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo "Provider settings written to ignored .runtime/provider-config.json.\n";
