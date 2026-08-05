<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Test owner whose translations must be changed through a package domain action.
 */
final class TestDomainManagedTranslatableModel extends TestTranslatableModel
{
    /**
     * Configure a domain-action-only translation mutation policy.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: TestTranslatableModelTranslation::class,
            foreignKey: 'test_translatable_model_id',
            fields: ['name', 'description'],
            fallbackLocales: ['en'],
            locales: ['en', 'bg', 'en-GB'],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }
}
