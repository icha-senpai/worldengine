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
        'stalls_cache_seconds' => (int) env('BITJITA_STALLS_CACHE_SECONDS', 300),
        'pool_concurrency' => (int) env('BITJITA_POOL_CONCURRENCY', 8),
        'requests_per_minute' => (int) env('BITJITA_REQUESTS_PER_MINUTE', 200),
        'user_requests_per_minute' => (int) env('BITJITA_USER_REQUESTS_PER_MINUTE', 150),
        'players_cache_seconds' => (int) env('BITJITA_PLAYERS_CACHE_SECONDS', 60),
        'player_cache_seconds' => (int) env('BITJITA_PLAYER_CACHE_SECONDS', 15),
        'player_inventories_cache_seconds' => (int) env('BITJITA_PLAYER_INVENTORIES_CACHE_SECONDS', 15),
        'player_passive_crafts_cache_seconds' => (int) env('BITJITA_PLAYER_PASSIVE_CRAFTS_CACHE_SECONDS', 15),
        'crafts_cache_seconds' => (int) env('BITJITA_CRAFTS_CACHE_SECONDS', 60),
        'stale_cache_seconds' => (int) env('BITJITA_STALE_CACHE_SECONDS', 300),
    ],

    'bitjuice' => [
        'enabled' => (bool) env('BITJUICE_ENABLED', true),
        'enabled_in_tests' => (bool) env('BITJUICE_ENABLED_IN_TESTS', false),
        'base_url' => env('BITJUICE_API_BASE_URL', 'https://bitjuiceapi.deeznuts.chat'),
        'timeout' => (int) env('BITJUICE_TIMEOUT', 5),
        'requests_per_minute' => (int) env('BITJUICE_REQUESTS_PER_MINUTE', 200),
        'user_requests_per_minute' => (int) env('BITJUICE_USER_REQUESTS_PER_MINUTE', 30),
        'players_cache_seconds' => (int) env('BITJUICE_PLAYERS_CACHE_SECONDS', 60),
        'player_cache_seconds' => (int) env('BITJUICE_PLAYER_CACHE_SECONDS', 30),
        'inventories_cache_seconds' => (int) env('BITJUICE_INVENTORIES_CACHE_SECONDS', 60),
        'passive_crafts_cache_seconds' => (int) env('BITJUICE_PASSIVE_CRAFTS_CACHE_SECONDS', 30),
        'crafts_cache_seconds' => (int) env('BITJUICE_CRAFTS_CACHE_SECONDS', 60),
        'stale_cache_seconds' => (int) env('BITJUICE_STALE_CACHE_SECONDS', 300),
        'failure_cooldown_seconds' => (int) env('BITJUICE_FAILURE_COOLDOWN_SECONDS', 30),
    ],

    'bitcraft_relay' => [
        'enabled' => (bool) env('BITCRAFT_RELAY_ENABLED', true),
        'enabled_in_tests' => (bool) env('BITCRAFT_RELAY_ENABLED_IN_TESTS', false),
        'base_url' => env('BITCRAFT_RELAY_BASE_URL', 'https://relay.bitcraftsync.app'),
        'timeout' => (int) env('BITCRAFT_RELAY_TIMEOUT', 8),
        'cache_seconds' => (int) env('BITCRAFT_RELAY_CACHE_SECONDS', 5),
    ],

    'bitcraft_spacetime' => [
        'enabled' => (bool) env('BITCRAFT_SPACETIME_ENABLED', true),
        'enabled_in_tests' => (bool) env('BITCRAFT_SPACETIME_ENABLED_IN_TESTS', false),
        'host' => env('BITCRAFT_SPACETIME_HOST', 'wss://bitcraft-early-access.spacetimedb.com'),
        'region_database' => env('BITCRAFT_SPACETIME_REGION_DATABASE', 'bitcraft-live-19'),
        'auth_token' => env('BITCRAFT_AUTH_TOKEN'),
        'static_snapshot_path' => env('BITCRAFT_SPACETIME_STATIC_SNAPSHOT') ?: storage_path('app/bitcraft/spacetime-static.json'),
        'sync_timeout' => (int) env('BITCRAFT_SPACETIME_SYNC_TIMEOUT', 45),
        'database_cache' => (bool) env('BITCRAFT_SPACETIME_DATABASE_CACHE', true),
        'tables' => array_values(array_filter(array_map('trim', explode(',', env(
            'BITCRAFT_SPACETIME_TABLES',
            implode(',', [
                'item_desc',
                'cargo_desc',
                'crafting_recipe_desc',
                'construction_recipe_desc',
                'extraction_recipe_desc',
                'deconstruction_recipe_desc',
                'item_conversion_recipe_desc',
                'terraform_recipe_desc',
                'resource_desc',
                'resource_clump_desc',
                'biome_desc',
                'resource_growth_recipe_desc',
                'resource_placement_recipe_desc',
                'building_desc',
                'building_type_desc',
                'building_claim_desc',
                'building_function_type_mapping_desc',
                'building_buff_desc',
                'building_repairs_desc',
                'building_spawn_desc',
                'tool_type_desc',
                'tool_desc',
                'skill_desc',
                'skill_level_knowledge_desc',
                'secondary_knowledge_desc',
                'knowledge_scroll_desc',
                'knowledge_scroll_type_desc',
                'knowledge_stat_modifier_desc',
                'paving_tile_desc',
                'pillar_shaping_desc',
                'equipment_desc',
                'weapon_desc',
                'weapon_type_desc',
                'combat_action_desc',
                'combat_action_multi_hit_desc',
                'enemy_desc',
                'enemy_scaling_desc',
                'enemy_ai_params_desc',
                'loot_table_desc',
                'loot_rarity_desc',
                'loot_chest_desc',
                'contribution_loot_desc',
                'npc_desc',
                'quest_chain_desc',
                'quest_stage_desc',
                'quest_drop_desc',
                'traveler_task_desc',
                'traveler_task_knowledge_requirement_desc',
                'traveler_trade_order_desc',
                'clothing_desc',
                'collectible_desc',
                'placeable_desc',
                'placeable_group_desc',
                'player_housing_desc',
                'emote_desc',
            ]),
        ))))),
    ],

    'bitcraft_live_companion' => [
        'enabled' => (bool) env('BITCRAFT_LIVE_COMPANION_ENABLED', false),
        'state_path' => env('BITCRAFT_LIVE_COMPANION_STATE_PATH')
            ?: (env('LOCALAPPDATA')
                ? env('LOCALAPPDATA').DIRECTORY_SEPARATOR.'BitCraftLiveCompanion'.DIRECTORY_SEPARATOR.'live-state.json'
                : storage_path('app/bitcraft/live-state.json')),
        'stale_after_seconds' => (int) env('BITCRAFT_LIVE_COMPANION_STALE_AFTER_SECONDS', 10),
    ],

];
