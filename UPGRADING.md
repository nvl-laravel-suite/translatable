# Upgrading NVL Translatable

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Adopting optional tenancy

Tenancy remains disabled by default. Applications that leave it disabled and
have not adopted Translatable resources keep legacy tables unchanged; do not
add tenant columns solely because this package is installed.

Before enabling tenancy, add `ownershipResource` to every related and self
translation definition, register each resource with the domain owner's
`TenantResourceRegistry`, and adopt the owning package. Tenant-only tables
require `tenant_id` in their owner/group locale uniqueness and composite
related foreign keys. Mixed platform/tenant tables require nullable
`tenant_id`, non-null `ownership_key` (`platform` or `tenant:<UUID>`), and
uniqueness partitioned by `ownership_key`.

Move every `TranslationWriter` call into a transaction on the model's actual
connection. Do not retain models or loaded translation relations across a
tenant or platform-mode change; retain scalar IDs and reload in the new
execution. Audit raw SQL and bulk query writes separately because they bypass
Eloquent ownership admission and must supply the exact tenant predicate while
preserving structural ownership columns.

## Adopting typed translation definitions

Existing `TranslatableOptions` and `SelfTranslatableOptions` declarations
continue to work through compatibility adapters. New and updated models should
use `defineTranslations()`.

For related rows:

```php
protected function defineTranslations(): RelatedTranslationDefinition
{
    return new RelatedTranslationDefinition(
        translationModel: ArticleTranslation::class,
        foreignKey: 'article_id',
        fields: ['title', 'summary'],
    );
}
```

Rename:

- `translatableOptions()` to `defineTranslations()`
- `TranslatableOptions` to `RelatedTranslationDefinition`
- `translatableFields` to `fields`
- `availableLocales` to `locales`

For grouped same-table rows, implement `SelfTranslatableModel`, use
`SelfTranslatable`, and return `SelfTranslationDefinition`. The group key is
required and the database must enforce a unique `(group_key, locale)` index.

The default fallback policy is now `configured`. Applications that require a
deterministic fallback to any persisted locale must explicitly select
`TranslationFallbackPolicy::AnyAvailable`. Exact reads should use
`withFallback: false` or `TranslationFallbackPolicy::ExactOnly`.

Central registered mutations now require owner and translation models to use
the same connection. Run the following before deployment:

```bash
php artisan nvl:translatable:doctor
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Self-row group and locale columns are now immutable after creation. Replace
direct identity updates with `setTranslation()`, `cloneTranslation()`, and
`deleteTranslation()`, or use the registered mutation actions.

Translated and shared declarations now reject Eloquent-managed primary-key,
timestamp, and soft-delete columns. Remove those fields from definitions and
leave their lifecycle to the model.

Persisted locale inventories and central payloads now include only supported,
canonically normalized locale rows. Before upgrading, backfill legacy values
such as `EN` to their canonical form such as `en`, and resolve any uniqueness
conflicts created by normalization.

Invalid configured resource metadata and malformed locale, fallback,
middleware, limit, or transaction-attempt settings now fail explicitly.
Correct these values before upgrading rather than relying on previous silent
filtering or defaults.

Model-specific `locales` may now only narrow `nvl-translatable.locales`. Add every
locale to the global catalog before selecting it in a model definition.

## Upgrading to 1.0

Version 1.0 removes magic translated properties, deprecated aliases, JOIN
hydration compatibility, legacy constructor forms, and no-op cache APIs.

1. Implement the contract and trait matching the resource's storage strategy.
2. Declare translated fields through a typed translation definition.
3. Replace magic access with explicit translation methods.
4. Register central resources through `TranslationResourceRegistry`.
5. Supply expected version hashes to central writes.
6. Establish and clear content locale at each request or job boundary.
7. Use `nvl:translations` for Laravel language files.

Backfill consumer data before removing old reads; the package does not perform
domain-specific conversion or generate migrations.

Do not build schema generation around translation declarations. They do not
contain SQL types, nullability, defaults, connection ownership, casts, or
migration history. Keep schema changes explicit and use the doctor to verify
the final database against each declaration.

## Shared owner registry compatibility

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

## Shared locale catalog cutover

Use `Nvl\Support\Contracts\LocaleCatalog` in package-facing locale consumers. Existing explicit published Translatable catalogs remain authoritative. New null catalog defaults derive from Core/application defaults; hosts that require a different content catalog must configure it explicitly or bind the contract. Supported values and stored regional normalization retain their existing representation.

Remove `nvl-primitives.locales.supported` after moving it to the selected catalog. It is deprecated for one major cycle and cannot override the Translatable adapter. Doctor reports conflicting catalogs without changing data. Validate resource narrowing and resource-specific fallbacks; excluded global fallback locales no longer invalidate a narrowed resource. Preserve exact-only policies and meaningful empty translated values.

Rebuild configuration caches and restart workers after catalog changes. Establish and clear scoped `ContentLocale` at each request/job boundary; an explicitly selected content locale remains independent of the UI locale. No automatic locale-row rewrite is included.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

## Application workflow contracts

Both resource workflows gain focused contracts. Authorizer and locale-preference defaults retain host bindings; scoped locale behavior and host-owned translation definitions remain native.

The supported workflow injection names are `DeleteTranslationResourceLocaleContract`, `SyncTranslationResourceContract`.

Inject these contracts when application workflows need substitution. Native
concrete constructors and operation signatures remain available through major 5;
internal workflow chains are unchanged. Register host implementations before
package discovery or replace the contract before resolving a new host service.
See [Testing your app](README.md#testing-your-app) for native fixtures and the
shipped consumer-audit PHPStan configuration.
