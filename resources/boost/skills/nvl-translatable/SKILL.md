---
name: nvl-translatable
description: Use when integrating localized Eloquent content with related translation tables or grouped locale rows, fallback reads, registered resource editing, revisions or content-locale context in Laravel.
---

# NVL Translatable

Translatable stores and resolves localized model content.
Use `nvl/translations` instead for Laravel PHP/JSON string catalogs.
The host owns its models/schema, HTTP locale selection, actor policy and presentation.

## References

- [Installation](references/installation.md): providers, config, publishing and optional Ownership setup.
- [API](references/api.md): definitions, model/trait contracts, writer/reader signatures and shapes.
- [Usage](references/usage.md): related/self models, registration, revision writes, locale and batch reads.

Inspect the recipe for the selected storage strategy before defining the model.

## Choose storage and register it

Use `TranslatableModel` plus `Translatable` with `RelatedTranslationDefinition` for a canonical owner and separate locale table.
Use `SelfTranslatableModel` plus `SelfTranslatable` with `SelfTranslationDefinition` for one logical group of locale rows.
Declare allowed fields/locales and fallback policy; never infer them from a model directory.
Related storage needs a same-connection owner/translation schema, parent foreign key and unique parent/locale index.
Self storage needs an immutable group key distinct from physical ID and a unique group/locale index.
Register each exact concrete model under `TranslationResourceRegistry`.
Declare search/display/order columns and maximum page size; substitutions require deliberate registration/schema compatibility.
With native Ownership, register/adopt the exact resources and required principals before admitted use.
A tenant-named column alone does not supply owner authority.

## Read the appropriate projection

Use `translated` for a field and `resolveTranslation` when provenance matters.
Empty strings and other non-null falsey values are real values.
`TranslationFallbackPolicy::Configured` fallback is the default; choose `TranslationFallbackPolicy::ExactOnly` or `TranslationFallbackPolicy::AnyAvailable` deliberately.
Content locale is separate from Laravel UI locale; `ContentLocale::withLocale` restores temporary context around request/job/nested work.
Explicit read locale arguments override model-instance/context defaults.
Related resolved-translation scopes preload rows; self scopes select preferred group rows. Use the strategy-specific read recipe.
Use `RelatedTranslationStore::rowsForOwners` for at most 1,000 related owners per batch.
Use `TranslationResourceGatherer` for authorized summaries, paginated resources and one normalized record.
Registered visibility/admission/preloads use writer connections and must remain fresh.

## Mutate through the complete writer

Bind `TranslationResourceAuthorizer`; the default denies every capability.
It receives explicit `Authenticatable|SystemActor`, ability, resource and optional record.
Eloquent actors are freshly reloaded; other providers retain their host freshness contract.
System actors need explicit authorization and may not come from client input.
Read the current opaque version through `TranslationResourceVersioner::version`.
Pass that mandatory string version to `TranslationWriter::sync` or `deleteLocale`.
The writer locks/revalidates the canonical model on its actual connection and enforces host policy/CAS.
Events follow the real outer commit; rollback cannot publish committed translation events.
Patch preserves omitted locales/fields; Replace removes omitted locales according to its storage contract.
Explicit null is a value, not omission; undeclared fields/locales and oversized payloads reject.
An omitted resource key is valid only for exactly one matching registration.
`TranslationMutationPolicy::OwningServiceOnly` resources reject generic writer mutations; their domain service owns the complete workflow.

## Operational constraints

Self groups protect their final live locale unless `allowDeletingLastTranslation` is explicitly enabled.
An allowed empty Replace can return an empty/nonpersisted or trashed group identity; do not treat it as a live writer target.
No package HTTP middleware, query/session/cookie policy or generic mutation endpoint is supplied.
Boot unowned features with optional Ownership physically absent; missing required owner context fails closed.
Do not cache canonical authority/schema admission in a consumer to reduce query count.
Native arrays/paginators/models are the output; consumer DTOs and frontend types stay in the application.

## Verify

Run `php artisan nvl:translatable:doctor --strict` after model/schema/registration changes.
`php artisan nvl:translatable:gather --json` exposes the command-line resource catalog.
Test both strategies, fallback/nulls, patch/replace, stale versions and final-locale behavior.
Test actor freshness, owner isolation, loaded-child validation, selected connections and context restoration.
Measure page-size scaling while preserving fresh admission; do not infer concurrent safety from sequential tests.
