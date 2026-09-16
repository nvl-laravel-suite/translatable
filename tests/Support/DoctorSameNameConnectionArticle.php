<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/** Represents an owner whose test connection shares a name but not an instance with its child. */
final class DoctorSameNameConnectionArticle extends Model implements TranslatableModel
{
    use HasUuids;
    use Translatable;

    private static ?Connection $resolvedConnection = null;

    protected $table = 'tenant_test_articles';

    /** Resolve one owner-only connection instance retaining the configured connection name. */
    public static function resolveConnection($connection = null): Connection
    {
        return self::$resolvedConnection ??= clone parent::resolveConnection($connection);
    }

    /** Declare the fixture's deliberately separate same-name child connection. */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: DoctorSameNameConnectionArticleTranslation::class,
            foreignKey: 'article_id',
            fields: ['name'],
            ownershipResource: 'test.articles',
        );
    }
}
