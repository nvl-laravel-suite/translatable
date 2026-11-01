# Translatable consumer recipes

Read [installation](installation.md) for provider/configuration ownership and [API](api.md) for exact declarations, payloads and outputs.

## Related locale rows

Assume the host owns `articles` and `article_translations` tables and an `ArticleTranslation` Eloquent model. Provision compatible primary keys, a parent foreign key with cascade deletion, `locale`, declared nullable/value columns and a unique `(article_id, locale)` index on the same connection. The host model is:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

final class Article extends Model implements TranslatableModel
{
    use Translatable;

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: ArticleTranslation::class,
            foreignKey: 'article_id',
            fields: ['title', 'body'],
            locales: ['en', 'bg'],
            fallbackLocales: ['en'],
        );
    }
}
```

Declare canonical fields/casts/relationships in the application as usual. Do not put identity, timestamps, foreign keys or ownership columns among translated fields. The trait owns translation read/storage seams, not a generic actor authorization grant. Model attribute casts can stage values only inside an owning domain workflow that provides its own complete validation and authority.

## Grouped self rows

For content whose logical resource consists of locale rows in its own table, use a stable group key distinct from each row's primary key:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

final class Entry extends Model implements SelfTranslatableModel
{
    use SelfTranslatable;

    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name', 'description'],
            sharedFields: ['type'],
            locales: ['en', 'bg'],
            fallbackLocales: ['en'],
        );
    }
}
```

Provision immutable group/locale identity and a unique group/locale index, including soft-deleted rows where that strategy is used. Shared fields must be disjoint from translated and structural fields. `allowDeletingLastTranslation` defaults false. Enable it only when the host domain can handle an empty logical group and create/restore a new representative through its own workflow.

## Register the resource and host policy

In a host provider's boot method:

```php
use App\Models\Article;
use Nvl\Translatable\Services\TranslationResourceRegistry;

app(TranslationResourceRegistry::class)->register(
    key: 'articles',
    modelClass: Article::class,
    label: 'Articles',
    searchableColumns: ['slug'],
    displayColumns: ['slug'],
    orderColumn: 'id',
    maximumPageSize: 50,
);
```

The example assumes an actual `slug` column. The registry accepts exact concrete model references or registered shared aliases; it does not guess models from tables. Registration is idempotent only for identical definitions. A changed subclass/schema needs matching registration and indexes, not a hidden model replacement.

Bind `TranslationResourceAuthorizer::allows(Authenticatable|SystemActor $actor, TranslationResourceAbility $ability, TranslationResourceDefinition $resource, ?Model $record = null): bool`. The host must choose permissions for Report, List, View, Synchronize and Delete. Default authorization denies all. Optional resource callbacks and query scopes further constrain rows; do not replace host row policy with a broad system enum grant.

Persist Eloquent actors and pass them explicitly; their canonical storage is reloaded before policy evaluation. Non-Eloquent authenticatable providers remain responsible for their identity/freshness contract. Native Ownership is optional: unowned resources boot without it, while protected resources require exact registry/adoption and admitted scope. Missing/changed owner state, generation or source denies access even for retained models.

## Read provenance and synchronize a version

Assume `$article` came from the host's admitted canonical query and `$actor` from trusted host authentication:

```php
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;

$title = $article->translated('title', 'bg');
$resolution = $article->resolveTranslation('title', 'bg');
$versioner = app(TranslationResourceVersioner::class);
$updated = app(TranslationWriter::class)->sync(
    owner: $article,
    translations: ['bg' => ['title' => 'Заглавие']],
    actor: $actor,
    expectedVersion: $versioner->version($article),
    mode: TranslationSyncMode::Patch,
    resourceKey: 'articles',
);
$deleted = app(TranslationWriter::class)->deleteLocale(
    owner: $updated,
    locale: 'bg',
    actor: $actor,
    expectedVersion: $versioner->version($updated),
    resourceKey: 'articles',
);
```

Versions are opaque strings, not integer counters. `sync` returns a model implementing the resource contract; `deleteLocale` returns bool. A resource key may be omitted only if exactly one resource matches the concrete model. Explicit keys still enforce model/source/query/actor admission. `TranslationMutationPolicy::OwningServiceOnly` rejects generic writer operations; call the owning domain service instead.

Patch keeps omitted locales and fields. Replace removes omitted locales; do not treat it as a partial update. Explicit null differs from omission and interacts with fallback-on-null reads. Unknown fields/locales, protected structural fields, excessive nesting/bytes and stale versions reject before persistence. Defaults bound mutations to 50 locales, 100 fields, 1,000,000 value bytes and depth 20. The service opens its actual model-connection transaction, locks canonical state, verifies policy/version and dispatches events after the enclosing commit. It supplies no general external-provider idempotency/outbox guarantee.

When final-locale deletion is enabled for self storage, empty Replace returns the logical identity with an empty relation; it may be nonpersisted after hard deletion or trashed after soft deletion. The event retains the terminal revision. No live representative remains for another generic write until the host creates/restores one.

`TranslationResolution` records requested/resolved locales, value, candidate chain and whether the requested row existed; use `usedFallback()`/`isMissing()` for provenance. Empty strings/zero/false remain values. Fallback defaults to `Configured` and on-null true, with configured candidates. Choose `ExactOnly` to prohibit fallback or `AnyAvailable` only with explicit product semantics. Model locales may narrow Core's catalog, never expand beyond it.

## Bounded reads and content locale

`TranslationResourceGatherer::summaries($actor)` returns native summaries, `gather('articles', $actor, $query)` a paginator of arrays, and `find('articles', $logicalKey, $actor)` one normalized array. Query keys are only `search`, `missing_locale`, `page`, `per_page`; fields and bounds come from resource registration. The record includes its version. Registered visibility, canonical admission and preloads use the writer connection.

Ordinary filtered summaries use one aggregate data query per resource for the total and exact locale coverage, retaining related policies and scoped self rows. Fresh authority/schema probes are additional. Grouping, HAVING, distinct projections, limits, offsets and locks retain the prior total-plus-per-locale count semantics; measure those host scopes with the actual declared locale list. No summary or admission result is cached across calls.

Apply the host's actor/visibility predicates to `$articleQuery` and `$entryQuery` before these scopes. For **related storage**, `withResolvedTranslations('bg')` validates the locale and eager-loads translation rows. It does not set the hydrated models' current locale; pass the locale to field reads:

```php
use App\Models\Article;

$articlePage = $articleQuery->withResolvedTranslations('bg')->paginate(25);
$articlePage->through(static fn (Article $article): array => [
    'id' => $article->translationResourceKey(),
    'title' => $article->translated('title', 'bg'),
]);
```

For **self storage**, the same scope selects one preferred locale row per logical group using the declared fallback chain. The resource ID is the stable group identity, not that representative's physical row ID. Explicit field locale arguments still determine field/null fallback resolution:

```php
use App\Models\Entry;

$entryPage = $entryQuery->withResolvedTranslations('bg')->paginate(25);
$entryPage->through(static fn (Entry $entry): array => [
    'id' => $entry->translationResourceKey(),
    'name' => $entry->translated('name', 'bg'),
]);
```

Use `getAllTranslations`/`getAvailableLocales` for admitted model reads. `RelatedTranslationStore::rowsForOwners($owners)` accepts a list of at most 1,000 owners and returns collections in input order. It groups actual storage sources, revalidates canonical owners and loaded children, and loads missing relations; never skip fresh admission because relations were previously loaded.

`ContentLocale` is independent of the UI locale. An explicit field-read locale wins over a model's `setLocale` override, which otherwise takes precedence over scoped context. For temporary context use the native callback, which restores the previous locale and explicit-context flag even if the callback throws:

```php
use Nvl\Translatable\Services\ContentLocale;

$title = app(ContentLocale::class)->withLocale(
    'bg',
    static fn (): mixed => $article->translated('title'),
);
```

Perform lazy iteration and projections that rely on the temporary locale inside the callback. `ContentLocale::get()` otherwise uses supported context, Laravel's supported app locale, then Core's catalog default; a model may further narrow its supported locales. The host chooses query/preference/session/cookie precedence; no package HTTP middleware is published. Native Ownership's context participant preserves/restores locale around its own scopes.

## Diagnostics and verification

```sh
php artisan nvl:translatable:doctor --strict
php artisan nvl:translatable:gather --json
```

Doctor inspects registration/schema/Ownership readiness without adopting or rewriting storage. Test related/self strategies, exact/fallback/null reads, patch/replace, terminal self groups, opaque stale revisions, after-commit rollback, source connections and worker context restoration. Test adversarial loaded children, wrong resource keys and revoked principals. Measure batch query scaling with real pages while retaining fresh admission checks; no consumer authorization/schema cache is part of the contract.
