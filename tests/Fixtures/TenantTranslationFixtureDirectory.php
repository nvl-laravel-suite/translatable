<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;

/** Resolves the two active translation fixture tenants. */
final class TenantTranslationFixtureDirectory implements TenantDirectory
{
    /** Resolve the fixture's two active tenants only. */
    public function find(TenantId $id): TenantDescriptor
    {
        if (! in_array($id->value, [TenantTranslationScenario::A, TenantTranslationScenario::B], true)) {
            throw new TenantNotFound;
        }

        return new TenantDescriptor($id, TenantStatus::Active);
    }
}
