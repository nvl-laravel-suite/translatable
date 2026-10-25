# Upgrading to the native Light contract

Translatable 6.x targets PHP 8.4, Laravel 13, and nvl/core 6.x. Its development suite uses Testbench 11. The optional nvl/ownership 6.x integration replaces the deprecated Tenancy bridge. Upgrade the selected dependency closure together; the wider package family remains on 6.x until its coordinated transition.

## Host mutation workflow

Replace package Action/DTO calls and immediate model write conveniences with a consumer-owned workflow that resolves a registered model and a persisted actor. Obtain the current revision with TranslationResourceVersioner::version(), then call TranslationWriter::sync() or TranslationWriter::deleteLocale() with that expected version. Both methods lock the canonical owner and resource, apply host authorization, and own their transaction. No implicit system actor or version is supplied. For a model registered under multiple resource keys, pass the exact key. It still enforces the model, storage, query scope, and actor policy.

    $version = $versioner->version($article);
    $article = $writer->sync($article, ['bg' => ['title' => 'Заглавие']], $user, $version);
    $writer->deleteLocale($article, 'bg', $user, $versioner->version($article));

Patch is the default. Pass TranslationSyncMode::Replace to delete omitted locales. Explicit null remains distinct from omission. Central writes to TranslationMutationPolicy::OwningServiceOnly resources are refused; their owning service controls its domain workflow and may use the existing model/storage extension seams.

The removed public operations are SyncTranslationResourceAction, DeleteTranslationResourceLocaleAction, their contracts and package Data classes, TranslationResourceQuery, TranslationWriter::upsert(), patch(), replace(), and delete(), plus immediate SelfTranslatable setTranslation(), cloneTranslation(), and deleteTranslation(). Consumers own HTTP, controllers, Actions, Data objects, and frontend types. Do not retain a package facade around retired methods.

Move the old `HandleContentLocale` middleware and its query/session/cookie settings into the host application. The source repository's `tests/Fixtures/Consumer/HandleContentLocale.php` is a working example using `consumer.content_locale.*` configuration. The package no longer publishes middleware code or `nvl-translatable.middleware` defaults, so the manifest no longer needs Symfony HTTP as a direct runtime dependency.

## Catalog and policy

TranslationResourceGatherer::summaries(), gather(), and find() take an Authenticatable or SystemActor and return native arrays or a paginator of arrays. Pass a validated native query array to gather() with search, missing_locale, page, and per_page keys. The default authorizer denies all; bind TranslationResourceAuthorizer to allow each ability. Background enum actors require an explicit host decision; ordinary model actors must be persisted.

A resource may be registered by host configuration or TranslationResourceRegistry::register(). Native Ownership applications must register exact protected owner and translation models with OwnershipResourceRegistry, run adoption, and enter an admitted owner or central scope. The old LegacyTranslationOwnership and Tenancy provider path is retired. Keep existing tenant-named columns if they are declared and adopted through native Ownership; do not infer authority from their names.

## Storage and release checks

Related-table and grouped self-row schemas remain supported. Keep locale uniqueness, owner partition keys, parent foreign keys, and soft-delete behavior. Review version preconditions, fallback, locale normalization, and owner-specific query scopes when porting tests. TranslationDoctor reports native readiness and schema findings.

When a self-row definition explicitly enables `allowDeletingLastTranslation`, a final delete or empty Replace removes the live group. Empty Replace returns a group identity with an empty relation, marked non-persisted after hard deletion or trashed after soft deletion; its event carries the terminal revision. The default remains final-row protection.

Install a fresh standalone application from the package's runtime dependency closure to verify that Data, TypeScript, HTTP extras, and Tenancy are absent. Rebuild configuration caches and restart long-lived workers after changing resource registration or adoption.
