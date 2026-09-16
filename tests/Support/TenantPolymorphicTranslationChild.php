<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents persisted allowlisted polymorphic ownership for service tests.
 *
 * @property string $id Persisted UUID.
 * @property string|null $tenant_id Canonical parent tenant UUID.
 * @property string $owner_type Allowlisted persisted parent type.
 * @property string $owner_id Persisted parent UUID.
 */
final class TenantPolymorphicTranslationChild extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'tenant_test_polymorphic_children';

    protected $fillable = [];

    /**
     * Resolve the canonical parent through the package allowlist.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }
}
