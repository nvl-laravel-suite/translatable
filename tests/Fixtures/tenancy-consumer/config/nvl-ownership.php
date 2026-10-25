<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'connection' => env('DB_CONNECTION'),
    'migrations' => ['enabled' => false],
    'central_operations' => ['translation-maintain'],
];
