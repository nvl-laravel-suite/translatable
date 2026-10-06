---
name: nvl-translatable
description: Design, implement, migrate, integrate, test, diagnose, audit, or review nvl/translatable in Laravel 13. Use for related translation tables, grouped same-table translations without owner rows, typed model declarations, deterministic fallback policies, request-scoped content locales, centralized resource registration and gathering, authorized mutation, optimistic concurrency, schema diagnostics, or package integrations.
---

# NVL Translatable

Keep model content in `nvl/translatable`. Keep Laravel UI strings in language
files managed by `nvl/translations`.

## Execute deliberately

1. Inspect the model, migration, key strategy, connection, casts, fillable
   fields, and existing mutation action.
2. Choose related or self storage from the resource's canonical identity.
3. Maintain explicit domain-owned models and migrations.
4. Add a typed `defineTranslations()` declaration.
5. Register stable resource metadata, visibility, and authorization.
6. Route writes through the owning transaction and mutation policy.
7. Run focused tests, static analysis, formatting, TypeScript checks, and
   `php artisan nvl:translatable:doctor --json`.

Tenancy is opt-in and disabled by default. Do not add ownership columns or
tenant tables to disabled, unadopted legacy consumers. In enabled deployments,
every `RelatedTranslationDefinition` and `SelfTranslationDefinition` must set
`ownershipResource` to a domain-owned resource registered and adopted through
`TenantResourceRegistry`; missing declarations or context fail closed.

Never generate or discover schema, models, fields, or storage strategies from
translation declarations. A declaration omits SQL types, nullability,
defaults, indexes, casts, relationships, connection ownership, and migration
history. Use explicit schema plus the doctor as the verification boundary.

## Choose storage deliberately

- Use related rows when a canonical owner stores meaningful
  locale-independent state.
- Use self rows when the logical resource is only grouped localized rows and
  an owner table would be empty.
- Never auto-detect fields or infer a strategy from table names.

For related rows, implement `TranslatableModel`, use `Translatable`, and return
`RelatedTranslationDefinition` from `defineTranslations()`.

For self rows, implement `SelfTranslatableModel`, use `SelfTranslatable`, and
return `SelfTranslationDefinition`. Require a stable group key. Separate
translated `fields` from structural `sharedFields`.

## Enforce schema invariants

- Use a locale column at least 35 characters wide.
- Require a unique owner/locale or group/locale index.
- Require cascade deletion for related translation rows.
- Keep related owner and translation models on the same connection.
- Match model key generation to the schema; UUID models must use Laravel's
  HasUuids trait.
- Align translation-model table, fillable fields, casts, relationships, and
  connection with its migration.
- Keep group, locale, foreign-key, and shared columns out of mutation payloads.
- Keep Eloquent-managed primary key, timestamp, and soft-delete columns out of
  translated and shared fields.
- Treat self-row group and locale identity as immutable.
- For tenant-only self rows, make `(tenant_id, group, locale)` unique.
- For tenant-only related rows, carry `tenant_id` into the child, make
  `(tenant_id, owner_id, locale)` unique, and reference the owner's composite
  `(tenant_id, id)` key.
- For mixed platform/tenant catalogs, use nullable `tenant_id`, non-null
  `ownership_key`, and partition uniqueness by `ownership_key`; valid values
  are `platform` and `tenant:<canonical UUID>`.

Run `php artisan nvl:translatable:doctor` after schema or configuration
changes.

## Resolve explicitly

- Use `translated()` for a value and `resolveTranslation()` for provenance.
- Preserve empty strings and other non-null falsey values.
- Default to `TranslationFallbackPolicy::Configured`.
- Resolve progressively less-specific parents for multi-segment locales.
- Use `TranslationFallbackPolicy::ExactOnly` when fallback is forbidden.
- Use `TranslationFallbackPolicy::AnyAvailable` only when product behavior
  explicitly permits it.
- Never select by insertion order.
- Use `getTranslation($locale, withFallback: false)` for exact row access.

Eager-load related translations for collections. Use `locale()` on self-row
queries to select one requested or fallback row per group.
Apply visibility constraints before `locale()`. Soft-deleted rows do not block
fallback by default; use `withTrashed()->locale(...)` to include them or
`onlyTrashed()->locale(...)` to select among deleted rows.

## Mutate safely

- Accept locale-keyed payloads and validate HTTP input with
  `SupportedLocaleMapRule`.
- Use `TranslationWriter` only inside a transaction on
  `$model->getConnection()`.
- Treat models and loaded translation relations as execution-local. Retain
  scalar IDs across tenant/mode changes and reload through the canonical
  tenant query.
- For model-local self-row mutations, use `setTranslation()`,
  `cloneTranslation()`, and `deleteTranslation()`; these preserve identity,
  grouped locking, final-row protection, deadlock retries, and loaded state.
- Self-row models support `Illuminate\Database\Eloquent\SoftDeletes`, including custom deleted-at columns.
  Writing a deleted locale restores its existing physical row, retains omitted
  translated fields, and refreshes shared fields. Keep the group/locale unique
  index across active and deleted rows; only active rows count toward final-row
  protection.
- Instance saves enforce self-row structure even when model events are muted;
  bulk query updates bypass Eloquent and must never change group or locale.
- Prefer `SyncTranslationResourceAction` and
  `DeleteTranslationResourceLocaleAction` for direct-mutation resources.
- Declare the DomainActionOnly translation mutation policy when package
  validation, optimistic concurrency, related-data synchronization, activity,
  or domain events must own the write.
- Default to `TranslationSyncMode::Patch`; require explicit
  `TranslationSyncMode::Replace`.
- Require expected versions and dispatch side effects after commit.
- Configure `nvl-translatable.transactions.attempts` for deadlock retries.
- Derive `TranslationActorData` on the server; never trust a client-supplied
  system actor.
- Never write translation rows from controllers, DTOs, observers,
  presentation services, or arbitrary model `fill()` / `save()` calls.
- Treat raw SQL, bulk query writes, muted model events, and externally loaded
  relations as host trust boundaries. They must apply exact ownership
  predicates and must never accept `tenant_id` or `ownership_key` from client
  payloads.

## Register and audit

- Register explicit stable resource keys in `TranslationResourceRegistry`.
- Whitelist search, display, and order columns; bound page size and define
  visibility explicitly.
- Bind application authorization; the package default fails closed for
  ordinary actors.
- Keep self-resource query scopes on group or consistently shared columns.
- Keep configuration serializable; register closures from a service provider.
- Verify every `TranslationWriter` call is inside a transaction on the
  model's connection.
- Verify DomainActionOnly resources use their owning package actions.
- Search for direct translation-row writes, arbitrary model saves, bulk query
  identity updates, model-directory scans, and cross-connection relations.
- Test both strategies for exact reads, configured fallback, null fallback,
  empty values, invalid locales and fields, patch/replace, final-row
  protection, query counts, connection selection, stale writes, payload
  limits, and after-commit events.
- Run package-scoped Pest, PHPStan, Pint, `nvl:translatable:doctor`, and
  `nvl:data:types:check`.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identities

- Declare owner class lists in `nvl-core.owners` and reference model classes in `translatable` capability configuration. Laravel `getMorphClass()` supplies the host-authored stored identity; declarations do not add global host morph mappings.
- Resource keys remain independent of owner aliases. Preserve search/display columns, query scopes, resource authorization, and the model translation mutation policy.
- Keep the package allowlist and authorization independent of Core registration. Never authorize a model merely because Core knows it.
- Preserve resolvers, handlers and authorization. Legacy aliases require agreement with native `getMorphClass()` and are removed in major 6; Doctor reports mismatches and stored identity drift without conversion.
- If the host changes its morph map, explicitly reconcile reviewed package-owned columns and affected host relations before cutover. Core and package capability registration never mutate the host morph map or rewrite stored values.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

## Canonical configuration ownership

- Read/write `nvl-translatable` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.

## Application workflow substitution

Both resource workflows gain focused contracts. Authorizer and locale-preference defaults retain host bindings; scoped locale behavior and host-owned translation definitions remain native.

The supported workflow injection names are `DeleteTranslationResourceLocaleContract`, `SyncTranslationResourceContract`.

Inject the supported contract into host orchestration and bind a native interface
mock or host implementation before resolving that orchestration. Keep concrete
constructors and native workflow bodies intact; internal chains remain package-owned.
Use declared DTOs or unsaved model identity handles for orchestration fixtures.
Use real package workflows and Laravel framework fakes for persistence, tenant,
queue, file, and external-effect integration checks. A host substitute proves
only the host call and result. Keep public declarations tagged `@api` and
constructor/configuration/private helpers internal.

Consult the owning README's Testing your app section for native examples. Include
`vendor/nvl/core/support/consumer-audit.neon` in host PHPStan and declare explicit
`nvlConsumer.testPaths`; the Suite workbench is not consumer tooling.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
