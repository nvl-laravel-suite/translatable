<?php

declare(strict_types=1);

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
