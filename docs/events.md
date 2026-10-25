# NVL Translatable events

The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json). These events have schema version `1`.

## Publication

`TranslationWriter` publishes sync and delete facts through `DomainEventDispatcher` on the resource model's source connection. Publication occurs after the outer commit. A rolled back write does not publish an event. `TranslationResourceGatherer` publishes a read observation on the resource model's connection. Host listeners and queue policies may defer delivery further. These callbacks are not an outbox or an exactly-once delivery guarantee.

## Payload

Every event carries bounded resource and operation facts. `actorType` and `actorId` identify the admitted actor for attribution: an Eloquent actor uses its morph type and persisted scalar authentication ID; another authenticated actor uses its class and scalar authentication ID; `SystemActor::Background` uses `SystemActor::class` and `Background`. These fields do not grant authority to a listener. Events never serialize the actor model or translated field content. Queued listeners must apply their own ownership and authorization checks before reading current storage.

The writer's returned version hashes describe the current translation rows and owner partition. Event `schemaVersion` is independent of those hashes. The owned mutation events capture an `OwnershipJobEnvelope` for queued handling.

## Canonical events

### TranslationResourceLocaleDeleted

[`TranslationResourceLocaleDeleted`](../src/Events/TranslationResourceLocaleDeleted.php) is emitted by [`TranslationWriter::deleteLocale`](../src/Services/TranslationWriter.php) after a locale row is deleted. Its public fields are `resource: string`, `ownerType: string`, `ownerId: int|string`, `locale: string`, `actorType: string`, `actorId: int|string`, `previousVersion: string`, `version: string`, and `schemaVersion: int = 1`. A no-op deletion emits no event.

### TranslationResourceSynced

[`TranslationResourceSynced`](../src/Events/TranslationResourceSynced.php) is emitted by [`TranslationWriter::sync`](../src/Services/TranslationWriter.php). Its public fields are `resource: string`, `ownerType: string`, `ownerId: int|string`, `locales: list<string>`, `mode: TranslationSyncMode`, `actorType: string`, `actorId: int|string`, `previousVersion: string`, `version: string`, and `schemaVersion: int = 1`.

### TranslationResourcesGathered

[`TranslationResourcesGathered`](../src/Events/TranslationResourcesGathered.php) is emitted by [`TranslationResourceGatherer::gather`](../src/Services/TranslationResourceGatherer.php). Its public fields are `resource: string`, `page: int`, `count: int`, `actorType: string`, `actorId: int|string`, and `schemaVersion: int = 1`. It is a repeatable read observation.

## Listener checks

Use the canonical event class for Laravel listeners and fakes. The ownership envelope restores the captured scope for queued mutation-event listeners, while the scalar actor facts remain attribution only. A listener that queries protected rows must use the host's native Ownership admission and resource authorization. The source connection's commit controls when a writer fact becomes visible to listeners.
