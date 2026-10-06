# NVL Translatable — API and usage

## Quickstart

```sh
composer require nvl/translatable:^5.0
php artisan nvl:install translatable --dry-run
php artisan nvl:install translatable
```

Required NVL dependencies: `nvl/core` (`^5.0`). Register the resource, owner and locale policy. Pass TranslationMutationData and TranslationActorData from validated host input. The host retains its native owner key.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Translatable\Contracts\SyncTranslationResourceContract` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Translatable\Contracts\SyncTranslationResourceContract;

/** @var SyncTranslationResourceContract $capability */
$result = $capability->execute($resourceKey, $ownerId, $mutation, $actor);
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/translatable/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/translatable/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/translatable:^5.0` |
| Module identifier | `nvl/translatable` |
| PHP namespace | `Nvl\Translatable` |
| Service provider | `Nvl\Translatable\Providers\TranslatableServiceProvider` |
| Configuration | `config/nvl-translatable.php` |

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
- [Tenant ownership](#tenant-ownership)
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
- `nvl/core` for public DTO and TypeScript declarations

## Installation

```bash
composer require nvl/translatable:^5.0
php artisan vendor:publish --tag=nvl-translatable-translations
php artisan vendor:publish --tag=nvl-translatable-config
```

Laravel package discovery registers `TranslatableServiceProvider`. The
package does not generate or run domain migrations. Each integrating package
or application owns its tables and models.

Laravel Boost discovers the bundled `nvl-translatable` skill from
`resources/boost/skills` during Boost installation or updates. It may also be
copied directly into the host application's `.agents/skills` directory:

```bash
php artisan vendor:publish --tag=nvl-translatable-skills
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

## Tenant ownership

Tenancy is opt-in and disabled by default. Disabled applications and
unadopted legacy translation models keep their existing schema and query
behavior; they do not need tenant tables, `tenant_id`, or `ownership_key`.
Once a host enables and adopts tenancy, every translatable model must name its
domain-owned tenant resource in code:

```php
return new SelfTranslationDefinition(
    groupKey: 'entry_key',
    fields: ['name'],
    ownershipResource: 'catalog.entries',
);

return new RelatedTranslationDefinition(
    translationModel: ArticleTranslation::class,
    foreignKey: 'article_id',
    fields: ['title'],
    ownershipResource: 'content.articles',
);
```

The resource key must also be registered with `TenantResourceRegistry` and
adopted through its owning package. Missing declarations, tenant context, or
adoption state fail closed.

Tenant-only self storage partitions the unique key by the canonical tenant:

```php
$table->uuid('tenant_id');
$table->unique(['tenant_id', 'entry_key', 'locale']);
```

Tenant-only related storage keeps ownership on both sides and prevents a
translation from crossing its canonical owner:

```php
$owner->unique(['tenant_id', 'id']);
$translation->uuid('tenant_id');
$translation->foreign(['tenant_id', 'article_id'])
    ->references(['tenant_id', 'id'])->on('articles')->cascadeOnDelete();
$translation->unique(['tenant_id', 'article_id', 'locale']);
```

Mixed platform/tenant catalogs use a non-null discriminator instead:

```php
$table->uuid('tenant_id')->nullable();
$table->string('ownership_key'); // platform or tenant:<canonical UUID>
$table->unique(['ownership_key', 'entry_key', 'locale']);
```

Treat loaded models and relations as valid only within the tenant execution
that loaded them. Reusing them after a tenant or mode change is rejected;
reload scalar identifiers through the canonical tenant query instead. Every
`TranslationWriter` call in enabled mode must run inside a transaction on the
owner's effective connection so canonical ownership and locale creation can
be locked together.

Eloquent scopes, relations, resource gathering, and writers enforce these
boundaries. Raw SQL, query-builder writes that bypass the package, disabled
model events, and externally hydrated relations are a host trust boundary:
the caller must apply the exact ownership predicate and preserve structural
columns. Never accept `tenant_id` or `ownership_key` from client translation
payloads.

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

Self-translated models may use Laravel's `SoftDeletes`, including a custom
deleted-at column. Deleted locale rows are excluded from normal reads and
fallback selection. Writing a deleted locale through `setTranslation()`,
`cloneTranslation()`, or `TranslationWriter` restores the existing physical
row, preserves omitted translated fields, and refreshes shared fields from the
representative row. The group/locale unique index still covers deleted rows.

Apply explicit visibility constraints before `locale()` so its preferred-row
query includes those predicates and the soft-delete scope. This includes
explicit trash filters:

```php
LocalizedEntry::query()->locale('bg')->get(); // Active requested or fallback rows.
LocalizedEntry::withTrashed()->locale('bg')->get(); // Include deleted candidates.
LocalizedEntry::onlyTrashed()->locale('bg')->get(); // Select among deleted rows.
```

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
`nvl-translatable.default_locale`.

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
`nvl-translatable.resources`. Configuration must remain serializable; do not use
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

The provider registers public DTOs with Core's Data provider:

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

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

## Testing your app

Inject `SyncTranslationResourceContract` or `DeleteTranslationResourceLocaleContract`
for registered-resource workflows. Resource authorizer, definitions, registry,
and host-owned translation models/builders retain their existing APIs and typed
related-row or grouped-row semantics. Locale state remains scoped.

```php
use Nvl\Translatable\Contracts\SyncTranslationResourceContract;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Data\TranslationMutationResultData;

final readonly class TranslateArticle
{
    public function __construct(private SyncTranslationResourceContract $translations) {}

    public function run(TranslationMutationData $mutation, TranslationActorData $actor): TranslationMutationResultData
    {
        return $this->translations->execute('articles', 'article-id', $mutation, $actor);
    }
}

$mutation = new TranslationMutationData(['en' => ['title' => 'Title']], 'version-1');
$actor = new TranslationActorData('user', 'user-id');
$result = new TranslationMutationResultData('articles', 'article-id', ['en'], 'version-2');
$translations = Mockery::mock(SyncTranslationResourceContract::class);
$translations->shouldReceive('execute')->once()
    ->with('articles', 'article-id', $mutation, $actor)->andReturn($result);
$this->app->instance(SyncTranslationResourceContract::class, $translations);
expect($this->app->make(TranslateArticle::class)->run($mutation, $actor))->toBe($result);
```

Translatable owns no Eloquent models or package factories: persisted fixtures
come from the host's own opted-in model factories and declared translation tables.
Construct the public mutation/result DTOs directly for orchestration tests.
Integration tests use the real authorizer, schema definitions, concurrency token,
translation storage, locale fallback, and scoped locale reset. A contract
substitute does not prove those behaviors or authorize arbitrary package reads.

In Laravel application tests, register a native Mockery interface mock or a small
implementation with `$this->app->instance(Contract::class, $substitute)` before
resolving your application service. A host binding installed before package
registration is retained; later contract replacements affect subsequent
resolutions. Rebuild previously resolved host services after replacing their
dependencies. Concrete implementations remain callable with their original
constructors through major 5. Mocks exercise your application orchestration;
package authorization, persistence, and external effects need real integration
tests.

Both resource workflows gain focused contracts. Authorizer and locale-preference defaults retain host bindings; scoped locale behavior and host-owned translation definitions remain native.

For static consumer checks, include the shipped
[`consumer-audit.neon`](https://github.com/nvl-laravel-suite/core/blob/main/support/consumer-audit.neon) from
`vendor/nvl/core/support/consumer-audit.neon` in your host PHPStan configuration
and configure explicit `nvlConsumer.testPaths` for factory-backed tests. The
extension checks supported APIs and model/query boundaries; it does not prove
authorization or arbitrary dynamic SQL.

## Shared owner identity

Declare a model once in `config/nvl-core.php`:

```php
'owners' => [Article::class],
```

Enable this package capability separately in `config/nvl-translatable.php`:

```php
'resources' => [
    'articles.editor' => [
        'model' => Article::class,
        'label' => 'Article translations',
        'display_columns' => ['slug'],
    ],
],
```

Resource keys remain independent of owner aliases. Preserve search/display columns, query scopes, resource authorization, and the model translation mutation policy. Core registration does not add the model to this package's allowlist.

Laravel's `getMorphClass()` determines stored identity. These class declarations do not install host morph maps. Keep resolvers, handlers and authorization independent; use `nvl:doctor --strict --format=json` to review legacy alias mismatches or stored identity drift. See [UPGRADING.md](UPGRADING.md) before changing the host's morph map.

## Shared content locale catalog

`LocaleRegistry` remains the validator for Translatable configuration. `LocaleRegistryCatalog` adapts it to Core's `Nvl\Support\Contracts\LocaleCatalog`, and package runtime consumers depend on that contract. Translatable selects its adapter across provider discovery order changes while preserving a host-bound catalog.

Explicit `nvl-translatable.locales`, `default_locale`, and `fallback_locales` continue to own the installed content catalog. Fresh null defaults inherit Core's explicit content catalog or the distinct valid application locale and fallback; they do not add English or Bulgarian automatically. `ContentLocale` remains scoped and an explicitly selected content locale does not change Laravel's UI locale. Keep setting and clearing it at request/job boundaries.

Definitions may narrow the global catalog. Explicit resource fallback locales must be supported by that resource; global fallbacks excluded by resource narrowing are skipped. Exact-only reads remain exact, any-available fallback remains explicit and deterministic, and empty translated strings do not become missing values.

Run `php artisan nvl:doctor` or `php artisan nvl:translatable:doctor` to find deprecated `nvl-primitives.locales` configuration and conflicting locale catalogs. Neither command changes stored locale values.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.

## Canonical configuration ownership

Use `nvl-translatable` settings in `config/nvl-translatable.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Translatable\Contracts\SyncTranslationResourceContract` in Laravel's native container for a host-workflow test:

```php
use Nvl\Translatable\Contracts\SyncTranslationResourceContract;

$double = Mockery::mock(SyncTranslationResourceContract::class);
$this->app->instance(SyncTranslationResourceContract::class, $double);
// Configure the exact execute arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

This package has no persistent fixture model in the supported factory inventory. Test value objects and contract inputs directly; do not invent a package model factory.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. New C3/C4/E tests, archives and guide execution remain pending until the integration phase records results.

## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-translatable::responsecode.operation_failed` |
| `invalid_locale` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-translatable::responsecode.invalid_locale` |
| `invalid_translatable_field` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-translatable::responsecode.invalid_translatable_field` |
| `invalid_translation_resource` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-translatable::responsecode.invalid_translation_resource` |


## License

Released under the [MIT License](LICENSE).
