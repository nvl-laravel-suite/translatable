<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Test owner model for the dedicated translation-table strategy.
 *
 * @property int $id
 * @property string|null $slug
 */
class TestTranslatableModel extends Model implements TranslatableModel
{
    use Translatable;

    protected $table = 'test_translatable_models';

    protected $fillable = [
        'slug',
    ];

    /**
     * Configure the model translation relationship and fields.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: TestTranslatableModelTranslation::class,
            foreignKey: 'test_translatable_model_id',
            fields: ['name', 'description'],
            fallbackLocales: ['en'],
            locales: ['en', 'bg', 'en-GB'],
        );
    }
}
