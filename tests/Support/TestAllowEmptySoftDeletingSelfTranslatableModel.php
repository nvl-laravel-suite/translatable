<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

/** Soft-delete self-row fixture with an explicitly removable final locale. */
final class TestAllowEmptySoftDeletingSelfTranslatableModel extends Model implements SelfTranslatableModel
{
    use SelfTranslatable;
    use SoftDeletes;

    public const DELETED_AT = 'archived_at';

    protected $table = 'test_soft_deleting_self_translatable_models';

    protected $fillable = ['entry_key', 'locale', 'name', 'description', 'type'];

    public $timestamps = false;

    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name', 'description'],
            sharedFields: ['type'],
            fallbackLocales: ['en'],
            allowDeletingLastTranslation: true,
        );
    }
}
