<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Test translation row stored on the owner's non-default connection.
 *
 * @property int $id
 * @property int $test_connected_model_id
 * @property string $locale
 * @property string|null $name
 */
final class TestConnectedTranslation extends Model
{
    protected $connection = 'translatable_tests';

    protected $table = 'test_connected_models_i18n';

    protected $fillable = [
        'locale',
        'name',
    ];

    public $timestamps = false;
}
