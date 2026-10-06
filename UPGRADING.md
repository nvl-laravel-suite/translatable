# Upgrading NVL Translatable

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

Model-specific `locales` may now only narrow `translatable.locales`. Add every
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

Move model identity declarations to `nvl-core.owners` and reference the alias from `translatable` capability configuration as described in the [README](README.md#shared-owner-identity). Preserve package contracts, resolvers/handlers, visibility scopes, and mutation authorization. Core does not grant package capabilities. Conflicting aliases or multiple canonical aliases for one model fail before use.

Legacy class inputs remain accepted for one major cycle and are reported through Core diagnostics. Existing Content, Taxonomy, and Metafields mappings keep their established aliases. Legacy SEO, Media, Comments, Templates, Pages, and Translatable class-backed behavior does not automatically create a new morph alias. Existing host morph mappings are respected.

Adding a canonical Core alias changes Laravel's write-time morph type for that model. Before adding it to an existing class-backed deployment, explicitly convert the known package-owned morph columns and reconcile every other affected host relationship. Keep unrelated rows and host-owned morph tables unchanged. This release performs no automatic owner-data conversion and does not ship `nvl:owners:upgrade`. Preserve existing aliases when no conversion is required, rebuild configuration caches, and restart workers after the cutover.

## Shared locale catalog cutover

Use `Nvl\Support\Contracts\LocaleCatalog` in package-facing locale consumers. Existing explicit published Translatable catalogs remain authoritative. New null catalog defaults derive from Core/application defaults; hosts that require a different content catalog must configure it explicitly or bind the contract. Supported values and stored regional normalization retain their existing representation.

Remove `primitives.locales.supported` after moving it to the selected catalog. It is deprecated for one major cycle and cannot override the Translatable adapter. Doctor reports conflicting catalogs without changing data. Validate resource narrowing and resource-specific fallbacks; excluded global fallback locales no longer invalidate a narrowed resource. Preserve exact-only policies and meaningful empty translated values.

Rebuild configuration caches and restart workers after catalog changes. Establish and clear scoped `ContentLocale` at each request/job boundary; an explicitly selected content locale remains independent of the UI locale. No automatic locale-row rewrite is included.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.
