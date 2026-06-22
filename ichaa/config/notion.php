<?php

return [

    'api_token' => env('NOTION_API_TOKEN'),

    // Keep the older database-query version by default because this sync layer
    // talks to Notion databases directly instead of the newer data-source split.
    'version' => env('NOTION_VERSION', '2022-06-28'),

    'base_url' => env('NOTION_API_BASE_URL', 'https://api.notion.com/v1'),

    'dataverse' => [
        'resources' => [
            'entities' => env('NOTION_DATAVERSE_ENTITIES_DATABASE_ID', 'a1497893b1174d3e97d89d7e464918aa'),
            'entity_aliases' => env('NOTION_DATAVERSE_ENTITY_ALIASES_DATABASE_ID', '743eb0b9002a48959f10e08f5bc5ba62'),
            'entity_notes' => env('NOTION_DATAVERSE_ENTITY_NOTES_DATABASE_ID', '743593d362aa4e06bd20de3e4ca9b079'),
            'entity_questions' => env('NOTION_DATAVERSE_ENTITY_QUESTIONS_DATABASE_ID', 'f35fb0b80f654403b93e47b25fd62199'),
            'documents' => env('NOTION_DATAVERSE_DOCUMENTS_DATABASE_ID', 'ac29cfe40cc94afc90dd1ffd31faac26'),
            'canon_references' => env('NOTION_DATAVERSE_CANON_REFERENCES_DATABASE_ID', '8a56361c421c43138eefa67bbde5af65'),
            'crossover_entry_points' => env('NOTION_DATAVERSE_CROSSOVER_ENTRY_POINTS_DATABASE_ID', '593ca4cb2a6a47f7a96db1ba12745886'),
            'relationships' => env('NOTION_DATAVERSE_RELATIONSHIPS_DATABASE_ID', 'ce100d73f42c45648ffe1583f3681b61'),
            'group_relationships' => env('NOTION_DATAVERSE_GROUP_RELATIONSHIPS_DATABASE_ID', '0e9c05dbd26a433a92009e002e1cfd15'),
            'faction_memberships' => env('NOTION_DATAVERSE_FACTION_MEMBERSHIPS_DATABASE_ID', '1e0341075aec401cbe09cfa358c38f8c'),
            'collections' => env('NOTION_DATAVERSE_COLLECTIONS_DATABASE_ID', '722e52a1921f43d09453e98889ae0269'),
            'glossary' => env('NOTION_DATAVERSE_GLOSSARY_DATABASE_ID', 'ff0053c19402445ba58b0dc3badbceff'),
            'timelines' => env('NOTION_DATAVERSE_TIMELINES_DATABASE_ID', '063ebe9e6dc64888b6a8a81cb2c5d0ad'),
            'character_states' => env('NOTION_DATAVERSE_CHARACTER_STATES_DATABASE_ID', '66f27b8f0019422592b2bfe6981b420d'),
            'concurrency_groups' => env('NOTION_DATAVERSE_CONCURRENCY_GROUPS_DATABASE_ID', 'b36b60f3bbad4a4ba95ee7a3451d08d0'),
            'power_interactions' => env('NOTION_DATAVERSE_POWER_INTERACTIONS_DATABASE_ID', '2a928a115a5143c09991f0a22c681465'),
            'travel_routes' => env('NOTION_DATAVERSE_TRAVEL_ROUTES_DATABASE_ID', '15869aafb17a41f9a3af15538c751123'),
            'location_containment' => env('NOTION_DATAVERSE_LOCATION_CONTAINMENT_DATABASE_ID', 'ce0d14d3600d43cf9543c8b91d4daace'),
            'location_control' => env('NOTION_DATAVERSE_LOCATION_CONTROL_DATABASE_ID', 'bc18ed91fe4d4e11933a161ad40a05bb'),
            'secrets' => env('NOTION_DATAVERSE_SECRETS_DATABASE_ID', '0fc12ac6eacf47988fdb5951f89acc09'),
            'knowledge_states' => env('NOTION_DATAVERSE_KNOWLEDGE_STATES_DATABASE_ID', '48892a65bd62419dbfc0fc64b1374b13'),
            'perception_states' => env('NOTION_DATAVERSE_PERCEPTION_STATES_DATABASE_ID', '2e01b8c5174c423a9f91d42ce78239bb'),
            'meta' => env('NOTION_DATAVERSE_META_DATABASE_ID', '14ec259fdb7648ce88b11bbe8a2cd767'),
            'pipeline_items' => env('NOTION_DATAVERSE_PIPELINE_ITEMS_DATABASE_ID', 'b823a8f8a5ec4d8ea9b0ebdb4012d663'),
            'session_logs' => env('NOTION_DATAVERSE_SESSION_LOGS_DATABASE_ID', '347c0af4776c4e569f94bbb91e1ce0fd'),
        ],

        'syncable_states' => array_values(array_filter(array_map(
            static fn (string $state) => trim($state),
            explode(',', env('NOTION_DATAVERSE_SYNCABLE_STATES', 'ready,synced'))
        ))),
    ],

];
