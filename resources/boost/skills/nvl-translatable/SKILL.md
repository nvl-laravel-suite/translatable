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

## Mutate safely

- Accept locale-keyed payloads and validate HTTP input with
  `SupportedLocaleMapRule`.
- Use `TranslationWriter` only inside a transaction on
  `$model->getConnection()`.
- For model-local self-row mutations, use `setTranslation()`,
  `cloneTranslation()`, and `deleteTranslation()`; these preserve identity,
  grouped locking, final-row protection, deadlock retries, and loaded state.
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
- Configure `translatable.transactions.attempts` for deadlock retries.
- Derive `TranslationActorData` on the server; never trust a client-supplied
  system actor.
- Never write translation rows from controllers, DTOs, observers,
  presentation services, or arbitrary model `fill()` / `save()` calls.

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
