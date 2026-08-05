<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Test translation row for a translatable owner.
 *
 * @property int $id
 * @property int $test_translatable_model_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 */
class TestTranslatableModelTranslation extends Model
{
    protected $table = 'test_translatable_models_i18n';

    protected $fillable = [
        'locale',
        'name',
        'description',
    ];

    public $timestamps = false;
}
