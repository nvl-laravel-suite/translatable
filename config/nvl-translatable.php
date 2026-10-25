<?php

declare(strict_types=1);

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    /*
    |--------------------------------------------------------------------------
    | Locale Catalog
    |--------------------------------------------------------------------------
    |
    | Models may narrow this catalog, but cannot add locales outside it.
    | Locale identifiers are normalized to BCP 47-style values. Null catalog
    | defaults derive from app.locale and app.fallback_locale. Published
    | explicit catalogs continue to take precedence over these defaults.
    */
    'locales' => null,

    'default_locale' => null,

    'fallback_locales' => null,

    /*
    |--------------------------------------------------------------------------
    | Deterministic Fallback
    |--------------------------------------------------------------------------
    */
    'fallback' => [
        'policy' => 'configured',
        'on_null' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mutation Safety
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'mutation_locales' => 50,
        'mutation_fields' => 100,
        'mutation_value_bytes' => 1_000_000,
        'mutation_depth' => 20,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale Labels
    |--------------------------------------------------------------------------
    */
    'labels' => [
        'en' => [
            'international' => 'English',
            'native' => 'English',
        ],
        'bg' => [
            'international' => 'Bulgarian',
            'native' => 'Български',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Central Translation Resources
    |--------------------------------------------------------------------------
    |
    | Packages normally register their resources from service providers. Host
    | applications may declaratively add their own models here.
    */
    'resources' => [],
];
