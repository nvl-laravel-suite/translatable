<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/**
 * Represents a mixed platform/tenant canonical owner for ownership service tests.
 *
 * @property string $id Persisted UUID.
 * @property string|null $tenant_id Canonical tenant UUID, or platform ownership.
 * @property string $ownership_key Explicit portable partition discriminator.
 */
final class TenantMixedTranslationOwner extends Model implements Ownable
{
    use HasOwnership;
    use HasUuids;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(TenantTranslationOwner::class, 'tenant_id');
    }

    public $timestamps = false;

    protected $table = 'tenant_test_mixed_owners';

    protected $fillable = [];
}
