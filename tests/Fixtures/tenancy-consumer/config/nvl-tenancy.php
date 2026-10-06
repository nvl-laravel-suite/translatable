<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'strategy' => 'shared-database',
    'connection' => env('DB_CONNECTION'),
    'profile' => 'application',
    'directory' => ['driver' => 'host', 'adapter' => null],
    'resolvers' => ['http' => null, 'public_site' => null],
    'access' => ['membership' => null, 'platform' => null],
    'resources' => [],
    'sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
    'migrations' => ['enabled' => false],
];
