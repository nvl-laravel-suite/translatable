<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Test translation model explicitly bound to the default testing connection.
 */
final class TestMismatchedConnectionTranslation extends Model
{
    protected $connection = 'testing';

    protected $table = 'test_connected_models_i18n';

    public $timestamps = false;
}
