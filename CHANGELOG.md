# Changelog

All notable changes to `nvl/translatable` are documented here.

## [Unreleased]

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Clamped gather-command page sizes to each registered resource maximum so
  oversized consumer input remains bounded instead of failing at runtime.
- Added consumer-contract coverage for serializable resource configuration,
  CLI output, diagnostics, locale policy, payload bounds, and both storage
  strategies; removed obsolete empty release directories.
- Added `RelatedTranslationDefinition` and `SelfTranslationDefinition` as
  typed, immutable model-level declarations.
- Promoted grouped same-table translations to a first-class storage strategy
  with logical group identity, whitelisted fields, shared-field copying,
  deterministic scopes, bounded writes, and final-row protection.
- Added explicit exact-only, configured, and any-available fallback policies.
- Added strategy-aware gathering, coverage, optimistic concurrency, central
  synchronization, deletion, and after-commit events.
- Made central mutations use and lock the registered model's connection and
  reject cross-connection related resources.
- Added race-safe locale creation, recursively canonical version hashes, and
  globally configurable payload limits.
- Added `nvl:translatable:doctor` for configuration, schema, index, foreign-key,
  connection, and table diagnostics.
- Added the storage strategy to public resource summary DTOs and generated
  TypeScript declarations.
- Migrated built-in package consumers to `defineTranslations()`.
- Retained `TranslatableOptions` and `SelfTranslatableOptions` as compatibility
  adapters.
- Standardized related model-localization tables on the
  `<owner_table>_i18n` convention.
- Hardened declarations against locale, foreign-key, group-key, shared-field,
  and primary-key collisions.
- Added progressive parent-locale fallback for multi-segment locale tags.
- Enforced model locale overrides as strict subsets of the global locale
  catalog and aligned global fallback chains with locale parents and defaults.
- Made self-row identity immutable and its convenience mutations
  transaction-, lock-, retry-, and loaded-state-aware.
- Added configurable deadlock retries for central mutations and authorization
  before mutation-policy disclosure.
- Made configured resources, payload shapes, locale catalogs, fallback
  settings, and middleware settings fail explicitly when malformed.
- Expanded diagnostics to cover resource metadata columns, transaction
  retries, middleware configuration, and exact custom owner-key references.
- Added stable pagination tie-breakers, cross-connection direct-write
  rejection, and recursively canonical object-shaped version values.
- Enforced grouped-row structural invariants when model events are muted or
  later listeners mutate structural fields,
  rejected Eloquent-managed translation columns, and made related locale
  inventories normalized and deterministic.
- Completed the integration documentation and agent guidance with executable
  UUID model examples, explicit schema ownership, registry configuration,
  middleware precedence, security boundaries, and an audit workflow.

## [1.0.0] - 2026-08-08

- Added explicit dedicated-row model translations and deterministic locale fallback.
- Added request/job-scoped locale state with lifecycle isolation.
- Added a central typed resource registry, gather and coverage DTOs, authorization, revisions, and after-commit events.
- Removed magic property access, compatibility hydration, cache no-ops, aliases, and HTTP concerns.
