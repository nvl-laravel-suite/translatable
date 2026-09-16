<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stores a fixture article's localized content under its canonical parent.
 *
 * @property string $id Persisted UUID.
 * @property string $tenant_id Canonical tenant UUID.
 * @property string $article_id Canonical article UUID.
 * @property string $locale Content locale.
 * @property string|null $name Localized name.
 * @property Carbon|null $created_at Creation time.
 * @property Carbon|null $updated_at Update time.
 */
final class TenantArticleTranslation extends Model
{
    use HasUuids;

    protected $table = 'tenant_test_article_translations';

    protected $fillable = ['locale', 'name'];

    /**
     * Resolve the canonical fixture article.
     *
     * @return BelongsTo<TenantArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(TenantArticle::class, 'article_id');
    }
}
