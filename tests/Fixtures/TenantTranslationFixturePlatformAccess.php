<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\Contracts\PlatformAccess;

/** Authorizes only the fixture's adoption operation. */
final readonly class TenantTranslationFixturePlatformAccess implements PlatformAccess
{
    /** Authorize only the fixture adoption operation. */
    public function authorize(PlatformOperation $operation): void
    {
        if ($operation->purpose !== 'fixture.adoption' || $operation->actorType !== 'test' || $operation->actorId !== 'fixture') {
            throw new TenantBoundaryViolation('The fixture platform operation is not authorized.');
        }
    }
}
