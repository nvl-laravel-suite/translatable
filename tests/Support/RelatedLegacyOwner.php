<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Nvl\Translatable\Enums\RelatedTranslationDeletedRowPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Owner whose legacy related rows use a soft-deleting table and a configurable deleted-row policy.
 */
final class RelatedLegacyOwner extends TestTranslatableModel
{
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: RelatedLegacyRow::class,
            foreignKey: 'test_translatable_model_id',
            fields: ['name', 'description'],
            fallbackLocales: ['en'],
            locales: ['en', 'bg', 'en-GB'],
            deletedRowPolicy: config('nvl-translatable.test_restore_deleted', false) ? RelatedTranslationDeletedRowPolicy::Restore : RelatedTranslationDeletedRowPolicy::Reject,
            cacheResolvedValues: true,
        );
    }
}
