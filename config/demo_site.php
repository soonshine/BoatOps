<?php

return [
    'enabled' => env('BOATOPS_DEMO_SITE_ENABLED', false) === true,
    'mode' => env('BOATOPS_DEMO_SITE_MODE', 'disabled'),
    'isolated_dataset' => env('BOATOPS_DEMO_SITE_ISOLATED_DATASET', false) === true,
    'organization_name' => 'Fictional Andaman Charter Lab',
    'actor_name' => 'Local Demo Site Actor',
    'public_reader_name' => 'Public Demo Reader',
    'public_reader_scopes' => ['operations.finance.read', 'operations.schedule.read'],
    'public_rate_limit_per_minute' => max(1, (int) env('BOATOPS_DEMO_SITE_RATE_LIMIT_PER_MINUTE', 60)),
    'allow_production_seed' => env('BOATOPS_DEMO_SITE_ALLOW_PRODUCTION_SEED', false) === true,
    'boat_names' => ['Plan A（虚构演示船）', 'Plan B（虚构演示船）'],
];
