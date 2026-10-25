<?php

declare(strict_types=1);

use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\TenantTranslationOwner;

return [
    'owners' => [TenantTranslationOwner::class, OwnershipActor::class],
];
