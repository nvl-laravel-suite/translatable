<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

/**
 * Represents self-translated content with a custom soft-delete column.
 *
 * @property int $id
 * @property string $entry_key
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 * @property string|null $type
 * @property Carbon|null $archived_at
 */
final class TestSoftDeletingSelfTranslatableModel extends Model implements SelfTranslatableModel
{
    use SelfTranslatable;
    use SoftDeletes;

    public const DELETED_AT = 'archived_at';

    protected $table = 'test_soft_deleting_self_translatable_models';

    protected $fillable = ['entry_key', 'locale', 'name', 'description', 'type'];

    public $timestamps = false;

    /**
     * Define locale-varying content and shared structural fields.
     */
    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name', 'description'],
            sharedFields: ['type'],
            fallbackLocales: ['en'],
        );
    }
}
