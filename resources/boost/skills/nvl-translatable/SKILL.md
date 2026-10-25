---
name: nvl-translatable
description: Use when designing, implementing, migrating, integrating, testing, diagnosing, auditing, or reviewing nvl/translatable in Laravel, including related translation tables, grouped self rows, fallback, content locale, native Ownership isolation, registered resource reads, authorized mutation, revisions, and schema diagnostics.
---

# NVL Translatable

Translatable owns locale storage and its native service contract. The host owns HTTP requests, controllers, Actions, Data objects, and frontend types. Do not invent a package facade to restore retired public operations.

The package publishes no HTTP middleware or query/session/cookie configuration. Use `tests/Fixtures/Consumer/HandleContentLocale.php` only as a host-owned example, and keep its `consumer.content_locale.*` settings in the application.

## Start with the concrete storage

1. Inspect the model, migration, actual connection, primary and logical keys, casts, indexes, and host authorization.
2. Choose related rows when a canonical owner has locale independent state. Choose grouped self rows when the logical resource consists only of locale rows.
3. Implement TranslatableModel with Translatable and RelatedTranslationDefinition, or SelfTranslatableModel with SelfTranslatable and SelfTranslationDefinition.
4. Declare fields, locales, fallback policy, and a stable group key for self rows. Never infer fields or schema from a model directory.
5. Register each concrete model and resource key in TranslationResourceRegistry. Whitelist search, display, ordering, and maximum page size.
6. If Ownership protects it, register the exact owner and translation models with OwnershipResourceRegistry, adopt their schema, and enter an admitted owner or central scope. A tenant-named column alone grants nothing.
7. Run focused Pest, PHPStan, Pint, and nvl:translatable:doctor checks.

Related rows need a same-connection parent relation, cascade foreign key, and unique parent/locale index. Self rows need immutable group/locale identity and a unique logical group/locale index. Native Ownership adds its declared owner scope key and durable admission requirements. Soft-deleted self locale rows may be restored by the storage workflow; keep uniqueness across active and deleted rows.

## Read and resolve

Use translated() for a value and resolveTranslation() for its provenance. Preserve empty strings and other non-null falsey values. Use Configured fallback by default, ExactOnly when fallback is forbidden, and AnyAvailable only when product behavior explicitly allows it. Explicit ContentLocale is scoped to request or job context and restored after a native Ownership callback.

Use getAllTranslations(), getAvailableLocales(), locale(), withResolvedTranslations(), and declared translated-field scopes. Apply model visibility and owner constraints before locale selection. Batch preloading should remain bounded as page size grows; do not admit a row from a different owner or resource query scope.

TranslationResourceGatherer::summaries(), gather(), and find() return native arrays or a paginator of arrays. They take an actual Illuminate\Contracts\Auth\Authenticatable or Nvl\Support\Enums\SystemActor. Gather accepts only search, missing_locale, page, and per_page query keys. The host validates its HTTP query and formats its own DTOs.

## Mutate through the complete service

Bind TranslationResourceAuthorizer in the host. Its default implementation denies access. Persist ordinary actors before writing; authorize SystemActor values explicitly. Derive both from trusted server state.

Read the current revision with TranslationResourceVersioner::version() on a canonical, admitted model. Pass the mandatory revision into TranslationWriter::sync() or deleteLocale(). The writer owns the model-connection transaction, locks and re-reads canonical storage, enforces resource and actor policy, verifies the revision, and dispatches events after commit.

    $version = $versioner->version($article);
    $article = $writer->sync(
        owner: $article,
        translations: ['bg' => ['title' => 'Заглавие']],
        actor: $user,
        expectedVersion: $version,
        mode: TranslationSyncMode::Patch,
        resourceKey: 'articles',
    );

Patch preserves omitted locales. Replace removes omitted locales. An explicit null is different from an omitted field. Reject unknown locales or fields, oversized payloads, stale revisions, and wrong model/resource combinations before mutation. The resource key may be omitted only when the concrete model has exactly one registration. An explicit key still enforces exact model, canonical storage, query scope, and actor policy.

`SelfTranslationDefinition::allowDeletingLastTranslation` is an explicit opt-in. Final delete and empty Replace then remove the live group; empty Replace returns the group identity with an empty relation, marked non-persisted after hard deletion or trashed after soft deletion. The committed event carries the terminal revision. Otherwise preserve final-row protection.

Use TranslationMutationPolicy::OwningServiceOnly when a domain service must own related validation or lifecycle. TranslationWriter rejects generic writes for such resources. Genuine model casts and storage hooks may stage or persist fields inside that separately authorized service workflow; they have no independent actor semantics. Avoid raw SQL, bulk query writes, and client-supplied ownership columns.

## Audit and verify

- Test related and grouped rows for exact/fallback/null reads, soft deletes, patch/replace, final self-row protection, version collisions, after-commit events, malformed payloads, and connection selection.
- Test native Ownership isolation for stale owner or generation, forged parent IDs, explicit out-of-scope resource keys, worker context restoration, and missing-runtime denial.
- Measure first and consecutive same-scope reads as well as page-size scaling. Preserve fresh readiness, store, and fence checks while reducing redundant queries.
- Run nvl:translatable:doctor --strict after schema or registration changes. The doctor inspects native Ownership readiness and resource schema; it does not adopt or rewrite rows.
- Keep nvl-translatable configuration and published tags canonical. Rebuild config caches and restart workers after reviewed registration or adoption changes.
