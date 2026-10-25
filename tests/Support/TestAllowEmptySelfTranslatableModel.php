<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

/** Self-row fixture that explicitly allows its final locale to be removed. */
final class TestAllowEmptySelfTranslatableModel extends Model implements SelfTranslatableModel
{
    use SelfTranslatable;

    protected $table = 'test_self_translatable_models';

    protected $fillable = ['entry_key', 'locale', 'name', 'description', 'type'];

    public $timestamps = false;

    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name', 'description'],
            sharedFields: ['type'],
            locales: ['en', 'bg', 'en-GB'],
            fallbackLocales: ['en'],
            allowDeletingLastTranslation: true,
        );
    }
}
