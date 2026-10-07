# NVL translatable events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Resource/morph-owner identity, locale list, sync mode, version hashes and actor only; no translated field content/model. Gathered is a read observation with page/count metadata.

Sync/delete writer guards govern publication. Gather operations are read observations and can repeat. The actual host owner/probe model connection is supplied rather than inventing a default write.

Owner references use the persisted Laravel morph identity and scalar key; version strings are resource version hashes, distinct from event schemaVersion.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [TranslationResourceLocaleDeleted](#translationresourcelocaledeleted) | 1 | Owner locale deleted. |
| [TranslationResourceSynced](#translationresourcesynced) | 1 | Owner localized resource synchronized. |
| [TranslationResourcesGathered](#translationresourcesgathered) | 1 | Localized resources read page gathered. |

### TranslationResourceLocaleDeleted

`Nvl\Translatable\Events\TranslationResourceLocaleDeleted` · [source](../src/Events/TranslationResourceLocaleDeleted.php) · event schema `1`.

Owner locale deleted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$resource` | `string` | public | `required` | — |
| `$ownerType` | `string` | public | `required` | — |
| `$ownerId` | `int\|string` | public | `required` | — |
| `$locale` | `string` | public | `required` | — |
| `$actor` | `Nvl\Translatable\Data\TranslationActorData` | public | `required` | — |
| `$previousVersion` | `string` | public | `required` | — |
| `$version` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$resource` | `string` | — |
| `$ownerType` | `string` | — |
| `$ownerId` | `int\|string` | — |
| `$locale` | `string` | — |
| `$actor` | `Nvl\Translatable\Data\TranslationActorData` | — |
| `$previousVersion` | `string` | — |
| `$version` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/DeleteTranslationResourceLocaleAction.php](../src/Actions/DeleteTranslationResourceLocaleAction.php) | `$owner->getConnection()` |

### TranslationResourceSynced

`Nvl\Translatable\Events\TranslationResourceSynced` · [source](../src/Events/TranslationResourceSynced.php) · event schema `1`.

Owner localized resource synchronized.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$resource` | `string` | public | `required` | — |
| `$ownerType` | `string` | public | `required` | — |
| `$ownerId` | `int\|string` | public | `required` | — |
| `$locales` | `array` | public | `required` | `list<string>` |
| `$mode` | `Nvl\Translatable\Enums\TranslationSyncMode` | public | `required` | — |
| `$actor` | `Nvl\Translatable\Data\TranslationActorData` | public | `required` | — |
| `$previousVersion` | `string` | public | `required` | — |
| `$version` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$resource` | `string` | — |
| `$ownerType` | `string` | — |
| `$ownerId` | `int\|string` | — |
| `$locales` | `array` | `list<string>` |
| `$mode` | `Nvl\Translatable\Enums\TranslationSyncMode` | — |
| `$actor` | `Nvl\Translatable\Data\TranslationActorData` | — |
| `$previousVersion` | `string` | — |
| `$version` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/SyncTranslationResourceAction.php](../src/Actions/SyncTranslationResourceAction.php) | `$owner->getConnection()` |

### TranslationResourcesGathered

`Nvl\Translatable\Events\TranslationResourcesGathered` · [source](../src/Events/TranslationResourcesGathered.php) · event schema `1`.

Localized resources read page gathered.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$resource` | `string` | public | `required` | — |
| `$page` | `int` | public | `required` | — |
| `$count` | `int` | public | `required` | — |
| `$actor` | `Nvl\Translatable\Data\TranslationActorData` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$resource` | `string` | — |
| `$page` | `int` | — |
| `$count` | `int` | — |
| `$actor` | `Nvl\Translatable\Data\TranslationActorData` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/TranslationResourceGatherer.php](../src/Services/TranslationResourceGatherer.php) | `$model->getConnection()` |

## Referenced payload types

Native event field types are listed above; nested declared fields and backed enum values follow. Private captured envelopes are included because serialized/queued objects retain them. Dates use `Carbon\CarbonImmutable`. Spatie Data serialization can also carry its protected transformation metadata; immutable serialized payload graphs are checked by the C4 contract suite.

### TranslationActorData

`Nvl\Translatable\Data\TranslationActorData` · [source](../src/Data/TranslationActorData.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$type` | `string` | — |
| `$id` | `int\|string\|null` | — |
| `$system` | `bool` | — |

### TranslationSyncMode

`Nvl\Translatable\Enums\TranslationSyncMode` · [source](../src/Enums/TranslationSyncMode.php).

Backed string values: `Patch = patch`, `Replace = replace`.

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

