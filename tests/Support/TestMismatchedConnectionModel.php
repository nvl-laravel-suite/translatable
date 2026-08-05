<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Test owner whose translation model intentionally declares another connection.
 */
final class TestMismatchedConnectionModel extends Model implements TranslatableModel
{
    use Translatable;

    protected $connection = 'translatable_tests';

    protected $table = 'test_connected_models';

    /**
     * Define a deliberately invalid cross-connection relationship.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: TestMismatchedConnectionTranslation::class,
            fields: ['name'],
            foreignKey: 'test_connected_model_id',
            locales: ['en', 'bg'],
        );
    }
}
