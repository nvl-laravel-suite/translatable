<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legacy related translation row that soft-deletes through a custom column.
 */
final class RelatedLegacyRow extends TestTranslatableModelTranslation
{
    use SoftDeletes;

    public const string DELETED_AT = 'archived_at';
}
