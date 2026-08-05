<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Test owner model stored on a non-default database connection.
 *
 * @property int $id
 * @property string|null $slug
 */
final class TestConnectedTranslatableModel extends Model implements TranslatableModel
{
    use Translatable;

    protected $connection = 'translatable_tests';

    protected $table = 'test_connected_models';

    protected $fillable = [
        'slug',
    ];

    /**
     * Define connection-aware related translations.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: TestConnectedTranslation::class,
            fields: ['name'],
            foreignKey: 'test_connected_model_id',
            locales: ['en', 'bg'],
        );
    }
}
