<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Represents a canonical tenant translation fixture.
 *
 * @property string $id Persisted UUID.
 * @property string $tenant_id Canonical tenant UUID.
 * @property string $slug Persisted slug.
 * @property Carbon|null $created_at Creation time.
 * @property Carbon|null $updated_at Update time.
 */
final class TenantArticle extends Model implements TranslatableModel
{
    use HasUuids;
    use Translatable;

    protected $table = 'tenant_test_articles';

    protected $fillable = ['slug'];

    /** Declare the fixture's domain-owned translation boundary. */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(translationModel: TenantArticleTranslation::class, foreignKey: 'article_id', fields: ['name'], ownershipResource: 'test.articles');
    }
}
