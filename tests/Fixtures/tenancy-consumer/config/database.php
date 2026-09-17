<?php

declare(strict_types=1);

$redisSocket = env('REDIS_SOCKET');

return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => env('DB_DATABASE'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_HOST'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => 'prefer',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client' => 'phpredis',
        'options' => ['prefix' => env('REDIS_PREFIX', '')],
        'default' => [
            'host' => is_string($redisSocket) && $redisSocket !== '' ? $redisSocket : env('REDIS_HOST', '127.0.0.1'),
            'port' => is_string($redisSocket) && $redisSocket !== '' ? 0 : (int) env('REDIS_PORT', 6379),
            'password' => null,
            'database' => 0,
        ],
    ],
];
