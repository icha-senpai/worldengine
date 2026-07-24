<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'dataverse_mcp' => [
        'token' => env('DATAVERSE_MCP_TOKEN'),
        'api_base' => env('DATAVERSE_MCP_API_BASE', '/api/v1'),
        'source' => env('DATAVERSE_MCP_SOURCE', 'mcp'),
    ],

    'bitjita' => [
        'base_url' => env('BITJITA_API_BASE_URL', 'https://bitjita.com'),
        'app_identifier' => env('BITJITA_APP_IDENTIFIER', 'Dataverse Bitcraft Tools'),
        'identity' => env('BITJITA_IDENTITY'),
        'token' => env('BITJITA_TOKEN'),
        'timeout' => (int) env('BITJITA_TIMEOUT', 12),
        'regions_cache_seconds' => (int) env('BITJITA_REGIONS_CACHE_SECONDS', 86400),
        'claims_cache_seconds' => (int) env('BITJITA_CLAIMS_CACHE_SECONDS', 300),
        'empires_cache_seconds' => (int) env('BITJITA_EMPIRES_CACHE_SECONDS', 600),
        'items_cache_seconds' => (int) env('BITJITA_ITEMS_CACHE_SECONDS', 3600),
        'market_cache_seconds' => (int) env('BITJITA_MARKET_CACHE_SECONDS', 60),
        'market_orders_cache_seconds' => (int) env('BITJITA_MARKET_ORDERS_CACHE_SECONDS', 30),
        'claim_market_listings_cache_seconds' => (int) env('BITJITA_CLAIM_MARKET_LISTINGS_CACHE_SECONDS', 30),
        'claim_details_cache_seconds' => (int) env('BITJITA_CLAIM_DETAILS_CACHE_SECONDS', 300),
        'claim_buildings_cache_seconds' => (int) env('BITJITA_CLAIM_BUILDINGS_CACHE_SECONDS', 300),
        'claim_inventories_cache_seconds' => (int) env('BITJITA_CLAIM_INVENTORIES_CACHE_SECONDS', 300),
        'stalls_cache_seconds' => (int) env('BITJITA_STALLS_CACHE_SECONDS', 300),
    ],

    'bitcraft_spacetime' => [
        'enabled' => (bool) env('BITCRAFT_SPACETIME_ENABLED', true),
        'enabled_in_tests' => (bool) env('BITCRAFT_SPACETIME_ENABLED_IN_TESTS', false),
        'host' => env('BITCRAFT_SPACETIME_HOST', 'wss://bitcraft-early-access.spacetimedb.com'),
        'region_database' => env('BITCRAFT_SPACETIME_REGION_DATABASE', 'bitcraft-live-19'),
        'auth_token' => env('BITCRAFT_AUTH_TOKEN'),
        'static_snapshot_path' => env('BITCRAFT_SPACETIME_STATIC_SNAPSHOT', storage_path('app/bitcraft/spacetime-static.json')),
        'sync_timeout' => (int) env('BITCRAFT_SPACETIME_SYNC_TIMEOUT', 45),
        'tables' => array_values(array_filter(array_map('trim', explode(',', env(
            'BITCRAFT_SPACETIME_TABLES',
            'item_desc,cargo_desc,crafting_recipe_desc,construction_recipe_desc,extraction_recipe_desc,building_desc,building_type_desc,tool_type_desc,tool_desc,skill_desc',
        ))))),
    ],

];
