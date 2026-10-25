<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

/**
 * Represents a canonical tenant translation fixture.
 *
 * @property string $id Persisted UUID.
 * @property string $tenant_id Canonical tenant UUID.
 * @property string $entry_key Persisted entry_key.
 * @property string $locale Persisted locale.
 * @property string|null $name Persisted name.
 * @property Carbon|null $created_at Creation time.
 * @property Carbon|null $updated_at Update time.
 * @property Carbon|null $deleted_at Deletion time.
 */
final class TenantSelfEntry extends Model implements Ownable, SelfTranslatableModel
{
    use HasOwnership;
    use HasUuids;
    use SelfTranslatable;
    use SoftDeletes;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(TenantTranslationOwner::class, 'tenant_id');
    }

    protected $table = 'tenant_test_entries';

    protected $fillable = ['entry_key', 'locale', 'name'];

    /** Declare the fixture's domain-owned translation boundary. */
    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(groupKey: 'entry_key', fields: ['name'], ownershipResource: 'test.entries');
    }
}
