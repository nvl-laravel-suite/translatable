<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\RelatedTranslationDeletedRowPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Related-table owner whose soft-deleting locale rows follow a configurable deleted-row policy.
 *
 * @property int $id
 * @property string|null $slug
 */
final class SoftDeletingRelatedOwner extends Model implements TranslatableModel
{
    use Translatable;

    protected $table = 'related_identity_owners';

    protected $fillable = ['slug'];

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: SoftDeletingRelatedTranslation::class,
            foreignKey: 'related_identity_owner_id',
            fields: ['name', 'description'],
            fallbackLocales: ['en'],
            locales: ['en', 'bg'],
            deletedRowPolicy: RelatedTranslationDeletedRowPolicy::tryFrom((string) config('nvl-translatable.test_deleted_row_policy'))
                ?? (config('nvl-translatable.test_restore_deleted', false)
                    ? RelatedTranslationDeletedRowPolicy::Restore
                    : RelatedTranslationDeletedRowPolicy::Reject),
        );
    }
}
