<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
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
