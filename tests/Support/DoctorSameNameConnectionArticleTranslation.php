<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Represents child storage on a distinct actual connection carrying the owner's connection name. */
final class DoctorSameNameConnectionArticleTranslation extends Model
{
    use HasUuids;

    private static ?Connection $resolvedConnection = null;

    protected $table = 'tenant_test_article_translations';

    /** Resolve one child-only connection instance retaining the configured connection name. */
    public static function resolveConnection($connection = null): Connection
    {
        return self::$resolvedConnection ??= clone parent::resolveConnection($connection);
    }

    /** Resolve the expected inherited owner. */
    public function article(): BelongsTo
    {
        return $this->belongsTo(DoctorSameNameConnectionArticle::class, 'article_id');
    }
}
