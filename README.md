# NVL Translatable — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^2.0` |
| Module identifier | `nvl/translatable` |
| PHP namespace | `Nvl\Translatable` |
| Service provider | `Nvl\Translatable\Providers\TranslatableServiceProvider` |
| Configuration | `config/translatable.php` |

Typed, deterministic Eloquent content translations for Laravel 13.

## Purpose

`nvl/translatable` supports two equal storage strategies:

- **Related rows**: a canonical owner row with translations in a dedicated
  table.
- **Self rows**: one localized row per locale in the resource table, grouped
  by a stable logical key, with no separate owner table.

The package owns model declarations, locale validation, deterministic
fallback, explicit reads and queries, bounded writes, request/job-scoped
content locale state, centralized resource management, authorization,
coverage reporting, optimistic concurrency, and after-commit events.

Laravel language-file strings remain a separate concern handled by
`nvl/translations`. The core runtime is transport-agnostic; optional HTTP
middleware integrates content-locale selection with requests, sessions, and
cookies.

## Contents

- [Installation and schema ownership](#installation)
- [Global configuration](#global-configuration)
- [Storage strategy](#choose-a-storage-strategy)
- [Related-row translations](#related-row-translations)
- [Self-row translations](#self-row-translations)
- [Model declarations](#model-level-overrides)
- [Fallback and model API](#fallback-policies)
- [Queries](#queries)
- [Content locale](#content-locale)
- [Writes](#writes)
- [Central resource registry](#central-resource-registry)
- [Gathering and diagnostics](#gather-and-diagnose)
- [Optimistic concurrency and events](#optimistic-concurrency-and-events)
- [TypeScript and development](#typescript)

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- `nvl/data` for public DTO and TypeScript declarations

## Installation

```bash
composer require nvl/laravel-suite:^2.0
php artisan vendor:publish --tag=translatable-config
```

Laravel package discovery registers `TranslatableServiceProvider`. The
package does not generate or run domain migrations. Each integrating package
or application owns its tables and models.

Laravel Boost discovers the bundled `nvl-translatable` skill from
`resources/boost/skills` during Boost installation or updates. It may also be
copied directly into the host application's `.agents/skills` directory:

```bash
php artisan vendor:publish --tag=translatable-skills
```

### Why schema generation is intentionally absent

A translation declaration is runtime policy, not a complete schema
specification. It does not encode SQL types, nullability, defaults, indexes,
casts, connection ownership, domain relationships, or migration history.
Generating models or migrations from it would make unsafe assumptions and
could overwrite domain decisions.

Keep migrations and models explicit in their owning package or application.
Use `defineTranslations()` as the canonical runtime declaration and
`nvl:translatable:doctor` to compare that declaration with the configured
database. The package never scans model directories or generates schema from
declarations.

## Global configuration

```php
return [
    'locales' => ['en', 'bg'],
    'default_locale' => 'en',
    'fallback_locales' => ['en'],

    'fallback' => [
        'policy' => 'configured',
        'on_null' => true,
    ],

    'limits' => [
        'mutation_locales' => 50,
        'mutation_fields' => 100,
        'mutation_value_bytes' => 1_000_000,
        'mutation_depth' => 20,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    'labels' => [
        'en' => ['international' => 'English', 'native' => 'English'],
        'bg' => ['international' => 'Bulgarian', 'native' => 'Български'],
    ],

    'middleware' => [
        'query_parameter' => 'content_lang',
        'session_key' => 'content_locale',
        'cookie_name' => 'content_locale',
        'cookie_minutes' => 525_600,
    ],

    'resources' => [],
];
```

Locale identifiers are normalized BCP 47-style values. Persist only the
canonical normalized value returned by the package; unsupported or
non-canonical legacy rows are excluded from resolution and central payloads.
Locale columns should be at least 35 characters.

Every model inherits the global locale catalog and fallback policy unless its
definition provides model-specific overrides. Invalid locale catalogs,
duplicate normalized locales, unsupported fallbacks, invalid policies, and
invalid limits or transaction attempts fail explicitly or are reported by the
doctor command.

## Choose a storage strategy

Use related rows when the resource has canonical, locale-independent state
such as ownership, status, routing, revision, or structural relationships.

Use self rows when the logical resource is only a group of localized rows and
a separate owner row would contain no meaningful state. A stable, immutable
group key identifies the logical resource.

Do not auto-detect translated columns. Both strategies require an explicit
typed model declaration.

## Related-row translations

A related translation table requires one owner/locale row, a composite unique
constraint, and a cascading owner foreign key:

```php
Schema::create('articles_i18n', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('article_id')->constrained()->cascadeOnDelete();
    $table->string('locale', 35);
    $table->string('title');
    $table->text('summary')->nullable();
    $table->timestampsTz();

    $table->unique(['article_id', 'locale']);
});
```

The translation model must match the translation table and generate the
primary-key type used by its migration:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArticleTranslation extends Model
{
    use HasUuids;

    public const string TABLE = 'articles_i18n';

    protected $table = self::TABLE;

    protected $fillable = [
        'article_id',
        'locale',
        'title',
        'summary',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
```

The UUID-backed owner implements `TranslatableModel` and returns a
`RelatedTranslationDefinition`:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

final class Article extends Model implements TranslatableModel
{
    use HasUuids;
    use Translatable;

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: ArticleTranslation::class,
            foreignKey: 'article_id',
            fields: ['title', 'summary'],
        );
    }
}
```

These examples use UUIDs, so both models use `HasUuids`. When an application
uses integer keys or ULIDs, keep the owner column, translation foreign key,
translation primary key, and both model key strategies aligned.

The owner and translation models must use the same database connection.
Central registration rejects cross-connection definitions because their
writes cannot be atomic.

Canonical identifiers such as handles, routing slugs, namespaces, and hashes
belong on the owner. Display copy belongs on translation rows.
Eloquent-managed primary key, timestamp, and soft-delete columns cannot be
declared as translated fields.

## Self-row translations

A self-translated table requires a stable group key and a unique group/locale
constraint:

```php
Schema::create('catalog_entries', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('entry_key');
    $table->string('locale', 35);
    $table->string('type');
    $table->string('name');
    $table->text('description')->nullable();
    $table->timestampsTz();

    $table->unique(['entry_key', 'locale']);
});
```

The model implements `SelfTranslatableModel` and generates the UUID declared
by the migration:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

final class CatalogEntry extends Model implements SelfTranslatableModel
{
    use HasUuids;
    use SelfTranslatable;

    protected $fillable = [
        'entry_key',
        'locale',
        'type',
        'name',
        'description',
    ];

    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name', 'description'],
            sharedFields: ['type'],
        );
    }
}
```

`fields` may vary by locale. `sharedFields` are copied from the representative
row when a new locale row is created and cannot be supplied through a
translation mutation. The group and locale columns are always structural and
cannot be translated or shared. Eloquent-managed primary key, timestamp, and
soft-delete columns cannot be translated or shared. The physical primary key,
group key, and locale key must be distinct.

Group and locale identity are immutable after a row is created. Use
`setTranslation()`, `cloneTranslation()`, and `deleteTranslation()` for
model-local convenience mutations. These methods use the model connection,
retry deadlocks, lock grouped rows before deletion, refresh preloaded group
state, and preserve final-row protection. Use the central actions when
authorization and optimistic concurrency are also required.

By default, deleting the final locale row is rejected. Set
`allowDeletingLastTranslation: true` only when an empty logical resource is a
valid domain state.

Central APIs identify a self-translated resource by its group value, not by
the primary key of one physical locale row.

## Model-level overrides

Both definitions accept:

- `fields`
- `localeKey`
- `locales`
- `fallbackPolicy`
- `fallbackLocales`
- `fallbackOnNull`
- `mutationPolicy`

Use model-level locale overrides only when a resource genuinely supports a
subset of the global locale catalog:

```php
return new RelatedTranslationDefinition(
    translationModel: LegalNoticeTranslation::class,
    fields: ['title', 'body'],
    locales: ['en', 'de'],
    fallbackPolicy: TranslationFallbackPolicy::ExactOnly,
    mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
);
```

`TranslatableOptions` and `SelfTranslatableOptions` remain compatibility
adapters for existing consumers. New models should use `defineTranslations()`
and the typed definitions.

Common definition options:

| Option | Meaning |
| --- | --- |
| `fields` | Explicit locale-varying columns |
| `localeKey` | Locale column, defaulting to `locale` |
| `locales` | Optional subset of the global locale catalog |
| `fallbackPolicy` | Exact, configured, or any-available resolution |
| `fallbackLocales` | Model-specific fallbacks before global fallbacks |
| `fallbackOnNull` | Model override for field-level null fallback |
| `mutationPolicy` | Direct generic writes or owning-domain-only writes |

Related definitions additionally accept `translationModel`, `foreignKey`, and
`ownerKey`. A custom foreign key may contain one `{table}` placeholder when a
reusable base declaration needs the owner table name.

Self definitions additionally require `groupKey` and accept `sharedFields`
and `allowDeletingLastTranslation`.

Use `TranslationMutationPolicy::Direct` for simple resources whose declared
fields can be safely persisted by `TranslationWriter` alone. Use
`TranslationMutationPolicy::DomainActionOnly` when translation changes must
pass through package validation, optimistic concurrency, related-data
synchronization, activity, or domain events. Domain-managed resources remain
available to central gathering and coverage reports, but the generic central
sync and delete actions reject direct mutations.

## Fallback policies

`TranslationFallbackPolicy` provides three explicit policies:

- `ExactOnly`: use only the requested locale.
- `Configured`: requested locale, progressively less-specific locale parents,
  model fallbacks, global fallbacks, then the configured default locale.
- `AnyAvailable`: the configured chain followed by persisted locales in
  normalized lexical order.

`Configured` is the default. The package never selects an arbitrary row based
on insertion order.

A `null` field continues through the chain when `fallback.on_null` is true.
An empty string, `false`, zero, and an empty array are intentional values and
do not fall back.

```php
$title = $article->translated('title', 'bg-BG');
$resolution = $article->resolveTranslation('title', 'bg-BG');

$resolution->requestedLocale;
$resolution->resolvedLocale;
$resolution->usedFallback();
$resolution->isMissing();
```

`getTranslation($locale, withFallback: false)` is always exact-only,
regardless of the model's configured fallback policy.

## Common model API

Both strategies expose the same explicit read surface:

| Method | Purpose |
| --- | --- |
| `translationDefinition()` | Validated immutable model declaration |
| `getCurrentLocale()` / `setLocale()` | Instance-specific locale override |
| `translated()` | Resolve one declared field |
| `resolveTranslation()` | Resolve a field with locale provenance |
| `getTranslatedAttributes()` | Resolve every declared translated field |
| `getTranslation()` | Return one exact or fallback row |
| `getAllTranslations()` | Return every row for the logical resource |
| `hasTranslation()` | Test for one exact supported locale |
| `getAvailableLocales()` | Return canonical persisted locales in lexical order |

`setLocale()` affects only that model instance. Use scoped `ContentLocale` for
request or job behavior.

## Queries

Related-row collections should eager-load translations:

```php
$articles = Article::query()
    ->withResolvedTranslations('bg')
    ->whereTranslated('title', 'like', '%Laravel%', locale: 'en')
    ->orderByTranslated('title', 'asc', 'bg')
    ->get();
```

Use `withAllTranslations()` for administrative editing.
Use `whereTranslationNull()` and `whereTranslationNotNull()` when null itself
is the query value; this avoids ambiguity with the shorthand operator syntax.

Self-row queries return one deterministic requested or fallback row per
logical group:

```php
$entries = CatalogEntry::query()
    ->where('type', 'public')
    ->locale('bg')
    ->orderBy('entry_key')
    ->get();
```

Fallback conditions are grouped so preceding query constraints cannot be
escaped by an `OR`.

## Content locale

`ContentLocale` is request/job scoped and independent of Laravel's UI-string
locale:

```php
$contentLocale->set('bg');
$contentLocale->get();
$contentLocale->is('bg');
$contentLocale->withLocale('en', fn () => $article->translated('title'));
```

`HandleContentLocale` may resolve locale preferences at an HTTP boundary.
Bind `ContentLocalePreferenceResolver` when an application stores per-user
content-locale preferences. Reset scoped locale state between long-running
jobs when the application does not use Laravel's normal scoped lifecycle.
When Laravel's application locale is unsupported, `ContentLocale` uses
`translatable.default_locale`.

The middleware accepts only supported locales and resolves sources in this
order:

1. Configured query parameter
2. Bound `ContentLocalePreferenceResolver`
3. Session
4. Cookie
5. Existing `ContentLocale` fallback to Laravel's application locale or the
   configured default

Query, resolver, and cookie selections are persisted to the enabled session
and cookie targets. Set a middleware source name to `null` to disable it.

## Writes

`TranslationWriter` supports both storage strategies and validates the entire
payload before changing rows:

```php
$connection = $article->getConnection();

$connection->transaction(function () use ($article, $writer): void {
    $writer->sync($article, [
        'en' => ['title' => 'Hello', 'summary' => null],
        'bg' => ['title' => 'Здравейте'],
    ], TranslationSyncMode::Patch);
});
```

- `Patch` updates supplied locales and preserves omitted rows.
- `Replace` updates supplied locales and removes omitted rows.
- Locale creation uses a unique-conflict-safe write path.
- Related writes reject owner and translation models on different
  connections, even when the resource is not centrally registered.
- Unsupported locales, normalized duplicates, undeclared fields, excessive
  size, and excessive nesting fail before a write.

`TranslationWriter` deliberately does not create a transaction. Application
actions must use the model's connection, not the default connection.

At HTTP boundaries, validate that the payload is a locale-keyed map before
passing it to a domain action:

```php
use Nvl\Translatable\Rules\SupportedLocaleMapRule;

return [
    'translations' => [
        'required',
        'array',
        new SupportedLocaleMapRule($article->translationDefinition()->supportedLocales()),
    ],
    'translations.*' => ['array'],
];
```

The rule validates locale keys. `TranslationWriter` performs the authoritative
locale, field, depth, count, and value-size validation before persistence.

For registered resources, prefer `SyncTranslationResourceAction` and
`DeleteTranslationResourceLocaleAction`. They lock the canonical owner or
entire self-row group, enforce expected-version concurrency, use the declared
connection, retry deadlocks according to `transactions.attempts`, and dispatch
events after that connection commits. Authorization is evaluated before a
domain-managed mutation policy is disclosed.

## Central resource registry

Packages and applications register editable resources explicitly:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Services\TranslationResourceRegistry;

final class ArticleServiceProvider extends ServiceProvider
{
    public function boot(TranslationResourceRegistry $translationResources): void
    {
        $translationResources->register(
            key: 'content.articles',
            modelClass: Article::class,
            label: 'Articles',
            searchableColumns: ['slug'],
            displayColumns: ['slug'],
            orderColumn: 'created_at',
            authorization: static fn (
                TranslationActorData $actor,
                TranslationResourceAbility $ability,
                ?Model $record,
            ): bool => $actor->id !== null,
        );
    }
}
```

Host applications may instead list model classes and metadata in
`translatable.resources`. Configuration must remain serializable; do not use
closures in configuration files. Unknown options, malformed column lists,
invalid page limits, and non-array resource configuration fail during package
boot instead of silently falling back.

```php
'resources' => [
    'content.articles' => [
        'model' => Article::class,
        'label' => 'Articles',
        'searchable_columns' => ['slug'],
        'display_columns' => ['slug'],
        'order_column' => 'created_at',
        'maximum_page_size' => 100,
    ],
],
```

Configuration supports only serializable metadata. Register from a service
provider when the resource needs an authorization closure or a `queryScope`.
Treat the query scope as part of authorization-sensitive visibility: central
reads and locked mutations both resolve records through it.

Registration is explicit and deterministic. The package does not scan models
or directories during requests.

Built-in integration keys are:

- `content.blocks`
- `forms.forms`
- `media.assets`
- `metafields.definitions`
- `metafields.values`
- `pages.pages`
- `seo.profiles`
- `taxonomy.terms`
- `templates.templates`

Built-in package resources declare `DomainActionOnly`: use each package's
mutation actions for writes. The central catalog remains the canonical place
to discover them, inspect coverage, and gather translation payloads.

The default authorizer fails closed for ordinary actors and permits explicitly
trusted system actors. Applications should bind their own
`TranslationResourceAuthorizer` or register a resource authorization closure.

## Gather and diagnose

```bash
php artisan nvl:translatable:gather --json
php artisan nvl:translatable:gather content.articles --missing=bg --search=guide --json
php artisan nvl:translatable:doctor
php artisan nvl:translatable:doctor --strict --format=json
```

Gathering operates on logical resources:

- Related rows produce one record per owner.
- Self rows produce one record per group and preload all locale rows in one
  additional query.
- Coverage counts logical resources rather than physical rows.
- Search columns, query scopes, ordering, and maximum page sizes are explicit.
- Pagination includes the logical resource key as a deterministic tie-breaker.

For self-row resources, query scopes define logical-resource visibility and
should use group or genuinely shared structural columns whose values remain
consistent across every locale row.

The doctor validates:

- Global locale, fallback, mutation-limit, middleware, and transaction
  configuration
- Declared owner, group, locale, translated, and shared columns
- Required owner/locale or group/locale unique indexes
- Related owner foreign keys and cascade behavior
- Owner/translation connection alignment
- Registered search, display, and order columns
- Availability of every registered persistence table

It returns a nonzero exit code for required invariant failures and supports
machine-readable JSON for CI and deployment checks.

Run the doctor after changing global configuration, a model declaration, a
table or connection, or registry metadata. Do not deploy while its JSON report
contains errors.

## Optimistic concurrency and events

Central mutations require the version returned by the gatherer. Versions hash
the logical resource key, owner timestamp, locale rows, translated values, and
translation timestamps using recursively canonicalized data. Stale writes are
rejected.

Successful central mutations dispatch:

- `TranslationResourceSynced`
- `TranslationResourceLocaleDeleted`

Events include actor, resource, logical identifier, affected locales, previous
version, and new version, and run only after commit.

## TypeScript

The provider registers public DTOs with `nvl/data`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Declarations use the `Nvl.Translatable.*` namespace. Resource summaries expose
their `related` or `self` storage strategy.

## Development

```bash
composer install
composer quality
php artisan nvl:translatable:doctor
```

See [UPGRADING.md](UPGRADING.md) for declaration migration and
[SECURITY.md](SECURITY.md) for authorization and mutation responsibilities.

## License

Released under the [MIT License](LICENSE).
