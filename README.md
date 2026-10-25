# NVL Translatable

Typed locale storage for Laravel 13 Eloquent models. The package supports a related translation table and grouped rows in the model's own table. It requires PHP 8.4 and nvl/core 6.x. nvl/ownership 6.x is optional for applications that declare protected resources.

## Declare storage

A related-table owner implements TranslatableModel and uses Translatable:

    final class Article extends Model implements TranslatableModel
    {
        use \Nvl\Translatable\Translatable;

        protected function defineTranslations(): \Nvl\Translatable\RelatedTranslationDefinition
        {
            return new \Nvl\Translatable\RelatedTranslationDefinition(
                translationModel: ArticleTranslation::class,
                foreignKey: 'article_id',
                fields: ['title', 'body'],
                locales: ['en', 'bg'],
                fallbackLocales: ['en'],
            );
        }
    }

A grouped-row model implements SelfTranslatableModel and uses SelfTranslatable:

    final class Entry extends Model implements SelfTranslatableModel
    {
        use \Nvl\Translatable\SelfTranslatable;

        protected function defineTranslations(): \Nvl\Translatable\SelfTranslationDefinition
        {
            return new \Nvl\Translatable\SelfTranslationDefinition(
                groupKey: 'entry_key',
                fields: ['name', 'description'],
                sharedFields: ['type'],
                locales: ['en', 'bg'],
                fallbackLocales: ['en'],
            );
        }
    }

Give related rows a foreign key and unique (foreign key, locale) index. Give grouped rows a stable group key, locale column, and unique logical group/locale index. When Ownership protects a resource, use its declared scope columns and adoption requirements too. getAllTranslations(), getAvailableLocales(), translated(), resolveTranslation(), and the model's locale/query scopes are available for reads. Attribute casts can stage fields for a host's authorized persistence workflow; they do not grant translation-management authority.

## Register resources and host policy

Register each concrete model under a resource key. The provider can read nvl-translatable.resources, or the host can register at boot:

    $resources->register(
        key: 'articles',
        modelClass: Article::class,
        label: 'Articles',
        searchableColumns: ['slug'],
        displayColumns: ['slug'],
    );

Bind Nvl\Translatable\Contracts\TranslationResourceAuthorizer for Report, List, View, Synchronize, and Delete decisions. Its allows() method receives the actual Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor, ability, resource, and optional record. The default authorizer denies access. Persist ordinary actors before mutation; authorize background system actors explicitly in the host. Do not derive system authority from client input.

When native Ownership is installed, register the exact owner and translation resources with OwnershipResourceRegistry, adopt their storage, and enter work through Ownership's runner or verified public site. Translatable checks that active scope, source connection, parent relation, store generation, and resource query scope on reads and writes. Without Ownership installed, unowned resources work through Core's inert ownership contracts. A missing required owner scope denies access.

## Read a revision and mutate

TranslationResourceVersioner::version($article) is the supported current revision read. A consumer should use the canonical model returned by its own admitted query, then obtain a fresh revision for a write. The gatherer's normalized records also include version.

    $version = $versioner->version($article);

    $updated = $writer->sync(
        owner: $article,
        translations: ['bg' => ['title' => 'Заглавие']],
        actor: $user,
        expectedVersion: $version,
        mode: \Nvl\Translatable\Enums\TranslationSyncMode::Patch,
        resourceKey: 'articles',
    );

    $deleted = $writer->deleteLocale(
        owner: $updated,
        locale: 'bg',
        actor: $user,
        expectedVersion: $versioner->version($updated),
        resourceKey: 'articles',
    );

TranslationWriter starts its own transaction on the model's actual connection, locks and revalidates the canonical resource, checks host authorization, and compares the mandatory expected revision before writing. Patch preserves omitted locales; Replace deletes them. Explicit null is a value and differs from an omitted field. Unsupported locales, undeclared fields, oversized payloads, stale versions, and out-of-scope resources fail before mutation. Events dispatch after commit.

The optional resource key may be omitted only when exactly one resource is registered for the concrete model. An explicit key must still match that model and its canonical storage, query scope, and actor policy. TranslationMutationPolicy::OwningServiceOnly reserves mutations for the owning domain service; generic TranslationWriter rejects them. Domain services may use the model's cast and storage seams inside their own authorized workflow.

## Catalog reads

TranslationResourceGatherer returns native arrays, not package DTOs:

    $summaries = $gatherer->summaries($user);
    $page = $gatherer->gather('articles', $user, [
        'search' => 'draft',
        'missing_locale' => 'bg',
        'page' => 1,
        'per_page' => 25,
    ]);
    $record = $gatherer->find('articles', $article->getKey(), $user);

Queries are bounded by the resource's allowed columns, stable order, and maximum page size. Related and grouped-row storage yield one logical resource per record. The host owns HTTP parsing, controllers, Actions, Data objects, and frontend types. This package does not register their providers or expose a generic HTTP mutation endpoint.

## Locale and diagnostics

ContentLocale is scoped to request/job context. Explicit locale arguments override it. Fallback returns the configured candidate when a locale or value is missing; callers can inspect TranslationResolution to distinguish missing, null, and fallback. Native Ownership context restoration also restores the content locale.

Run php artisan nvl:translatable:doctor --strict to inspect registered resources and schema. Use php artisan nvl:translatable:gather --json for a command-line catalog. The package tests cover related and grouped storage, revisions, fallback, native owner isolation, adoption, queue restoration, and malformed envelopes.

See [UPGRADING.md](UPGRADING.md) for the 6.x contract change and [SECURITY.md](SECURITY.md) for admission requirements.
