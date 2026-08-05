<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

/**
 * Test model for grouped same-table translation storage.
 *
 * @property int $id
 * @property string $entry_key
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 * @property string|null $type
 */
final class TestSelfTranslatableModel extends Model implements SelfTranslatableModel
{
    use SelfTranslatable;

    protected $table = 'test_self_translatable_models';

    protected $fillable = [
        'entry_key',
        'locale',
        'name',
        'description',
        'type',
    ];

    public $timestamps = false;

    /**
     * Define grouped same-table translation behavior.
     */
    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name', 'description'],
            sharedFields: ['type'],
            locales: ['en', 'bg', 'en-GB'],
            fallbackLocales: ['en'],
        );
    }
}
