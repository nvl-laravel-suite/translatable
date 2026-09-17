<?php

declare(strict_types=1);

return [
    'name' => 'Tenancy translation fixture',
    'env' => env('APP_ENV', 'testing'),
    'debug' => false,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file', 'store' => null],
];
