<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Test translation row with Eloquent-managed timestamps enabled.
 */
final class TestTimestampedTranslation extends Model
{
    protected $table = 'test_timestamped_translations';
}
