<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Events\TranslationResourceLocaleDeleted;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\TranslationDefinition;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Validates and persists translation maps through the model's declared storage strategy.
 *
 * @api
 */
final readonly class TranslationWriter
{
    /**
     * Create the strategy-aware translation writer.
     */
    public function __construct(
        private LocaleCatalog $locales,
        private RelatedTranslationStore $relatedStore,
        private SelfTranslationStore $selfStore,
        private TranslationPayloadValidator $payloadValidator,
        private TranslationOwnership $ownership,
        private TranslationResourceRegistry $resources,
        private TranslationResourceAuthorization $authorization,
        private TranslationResourceVersioner $versioner,
        private TranslationResourceLocator $locator,
        private Repository $config,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Synchronize translations using an explicit patch or replace contract.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return Model&TranslatableResourceModel
     */
    public function sync(
        Model&TranslatableResourceModel $owner,
        array $translations,
        Authenticatable|SystemActor $actor,
        string $expectedVersion,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
        ?string $resourceKey = null,
    ): Model {
        $this->assertPersistedResource($owner);
        $resource = $this->resolveResource($owner, $resourceKey);
        $this->assertActor($actor);

        try {
            return $owner->getConnection()->transaction(function () use ($owner, $translations, $actor, $expectedVersion, $mode, $resource): Model {
                $this->ownership->lockOwner($owner, $owner->translationDefinition());
                $record = $this->locator->lock($resource, $owner->translationResourceKey());
                $this->authorization->authorize($actor, TranslationResourceAbility::Synchronize, $resource, $record);
                $this->assertDirectMutation($record, $resource->key);
                $previousVersion = $this->versioner->version($record);
                $this->assertVersion($resource->key, $previousVersion, $expectedVersion);
                $normalized = $this->normalizeTranslationMap($record, $translations);

                foreach ($normalized as $locale => $attributes) {
                    $this->storeUpsert($record, $record->translationDefinition(), $locale, $attributes);
                }
                if ($mode === TranslationSyncMode::Replace) {
                    $this->deleteExcept($record, array_keys($normalized));
                }

                $record = $this->locator->lock($resource, $owner->translationResourceKey());
                $this->locator->loadTranslations(new Collection([$record]));
                $version = $this->versioner->version($record);
                $actorIdentity = TranslationEventActor::identity($actor);
                $this->domainEvents->dispatch(new TranslationResourceSynced(
                    resource: $resource->key,
                    ownerType: $record->getMorphClass(),
                    ownerId: $record->translationResourceKey(),
                    locales: array_keys($normalized),
                    mode: $mode,
                    actorType: $actorIdentity['type'],
                    actorId: $actorIdentity['id'],
                    previousVersion: $previousVersion,
                    version: $version,
                ), $record->getConnection());

                return $record;
            }, $this->transactionAttempts());
        } finally {
            $owner->unsetRelation('translations');
        }
    }

    /** Delete one locale after checking native admission and the caller's expected revision. */
    public function deleteLocale(
        Model&TranslatableResourceModel $owner,
        string $locale,
        Authenticatable|SystemActor $actor,
        string $expectedVersion,
        ?string $resourceKey = null,
    ): bool {
        $this->assertPersistedResource($owner);
        $resource = $this->resolveResource($owner, $resourceKey);
        $this->assertActor($actor);

        try {
            return $owner->getConnection()->transaction(function () use ($owner, $locale, $actor, $expectedVersion, $resource): bool {
                $this->ownership->lockOwner($owner, $owner->translationDefinition());
                $record = $this->locator->lock($resource, $owner->translationResourceKey());
                $this->authorization->authorize($actor, TranslationResourceAbility::Delete, $resource, $record);
                $this->assertDirectMutation($record, $resource->key);
                $previousVersion = $this->versioner->version($record);
                $this->assertVersion($resource->key, $previousVersion, $expectedVersion);
                $normalizedLocale = $record->translationDefinition()->assertLocale($this->locales->assertSupported($locale));
                $deleted = $this->deleteStoredLocale($record, $normalizedLocale);

                if ($deleted) {
                    $record = $this->locator->lock($resource, $owner->translationResourceKey());
                    $this->locator->loadTranslations(new Collection([$record]));
                    $actorIdentity = TranslationEventActor::identity($actor);
                    $this->domainEvents->dispatch(new TranslationResourceLocaleDeleted(
                        resource: $resource->key,
                        ownerType: $record->getMorphClass(),
                        ownerId: $record->translationResourceKey(),
                        locale: $normalizedLocale,
                        actorType: $actorIdentity['type'],
                        actorId: $actorIdentity['id'],
                        previousVersion: $previousVersion,
                        version: $this->versioner->version($record),
                    ), $record->getConnection());
                }

                return $deleted;
            }, $this->transactionAttempts());
        } finally {
            $owner->unsetRelation('translations');
        }
    }

    /** Resolve an exact catalog entry when one model has multiple visibility registrations. */
    private function resolveResource(Model&TranslatableResourceModel $owner, ?string $resourceKey): TranslationResourceDefinition
    {
        $resource = $resourceKey === null ? $this->resources->forModel($owner) : $this->resources->get($resourceKey);
        if ($resource->modelClass !== $owner::class) {
            throw TranslationResourceException::invalid('The translation resource key does not match the owner model.');
        }

        return $resource;
    }

    /** Reject stale or missing revision preconditions. */
    private function assertVersion(string $resource, string $actual, string $expected): void
    {
        if ($expected === '' || ! hash_equals($actual, $expected)) {
            throw TranslationResourceException::stale($resource);
        }
    }

    /** Reject non-persisted ordinary actors without granting system authority. */
    private function assertActor(Authenticatable|SystemActor $actor): void
    {
        if ($actor instanceof Authenticatable
            && ($actor->getAuthIdentifier() === null || ($actor instanceof Model && ! $actor->exists))) {
            throw TranslationResourceException::invalid('Translation mutations require a persisted actor.');
        }
    }

    /** Keep package-specific mutations outside the generic resource writer. */
    private function assertDirectMutation(Model&TranslatableResourceModel $record, string $resource): void
    {
        if ($record->translationDefinition()->mutationPolicy === TranslationMutationPolicy::OwningServiceOnly) {
            throw TranslationResourceException::requiresOwningService($resource);
        }
    }

    /** @return positive-int */
    private function transactionAttempts(): int
    {
        $attempts = $this->config->get('nvl-translatable.transactions.attempts', 3);
        if (! is_int($attempts) || $attempts < 1) {
            throw TranslationResourceException::invalid('The translatable.transactions.attempts value must be a positive integer.');
        }

        return $attempts;
    }

    /**
     * Normalize and validate a complete locale-keyed translation map.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    private function normalizeTranslationMap(
        Model&TranslatableResourceModel $owner,
        array $translations,
    ): array {
        $this->assertPersistedResource($owner);
        $definition = $owner->translationDefinition();
        $this->payloadValidator->validate($definition, $translations);
        $normalized = [];

        foreach ($translations as $locale => $attributes) {
            $resolvedLocale = $definition->assertLocale(
                $this->locales->assertSupported($locale),
            );

            if (array_key_exists($resolvedLocale, $normalized)) {
                throw new TranslatableException(
                    "Duplicate normalized translation locale [{$resolvedLocale}].",
                );
            }

            $this->assertDeclaredFields($definition, $attributes);
            $normalized[$resolvedLocale] = $attributes;
        }

        return $normalized;
    }

    /**
     * Persist one locale through the matching storage strategy.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storeUpsert(
        Model&TranslatableResourceModel $owner,
        TranslationDefinition $definition,
        string $locale,
        array $attributes,
    ): Model {
        return match (true) {
            $owner instanceof TranslatableModel
                && $definition instanceof RelatedTranslationDefinition => $this->relatedStore->upsert(
                    $owner,
                    $definition,
                    $locale,
                    $attributes,
                ),
            $owner instanceof SelfTranslatableModel
                && $definition instanceof SelfTranslationDefinition => $this->selfStore->upsert(
                    $owner,
                    $definition,
                    $locale,
                    $attributes,
                ),
            default => throw new TranslatableException('The model and translation storage definition do not match.'),
        };
    }

    /** Delete one exact locale through its declared storage strategy. */
    private function deleteStoredLocale(Model&TranslatableResourceModel $owner, string $locale): bool
    {
        $definition = $owner->translationDefinition();

        return match (true) {
            $owner instanceof TranslatableModel && $definition instanceof RelatedTranslationDefinition => $this->relatedStore->delete($owner, $definition, $locale),
            $owner instanceof SelfTranslatableModel && $definition instanceof SelfTranslationDefinition => $this->selfStore->delete($owner, $definition, $locale),
            default => throw new TranslatableException('The model and translation storage definition do not match.'),
        };
    }

    /**
     * Delete translation rows outside an explicit locale set.
     *
     * @param  list<string>  $locales
     */
    private function deleteExcept(
        Model&TranslatableResourceModel $owner,
        array $locales,
    ): void {
        $definition = $owner->translationDefinition();

        match (true) {
            $owner instanceof TranslatableModel
                && $definition instanceof RelatedTranslationDefinition => $this->relatedStore->deleteExcept(
                    $owner,
                    $definition,
                    $locales,
                ),
            $owner instanceof SelfTranslatableModel
                && $definition instanceof SelfTranslationDefinition => $this->selfStore->deleteExcept(
                    $owner,
                    $definition,
                    $locales,
                ),
            default => throw new TranslatableException('The model and translation storage definition do not match.'),
        };
    }

    /**
     * Reject writes for a model that cannot identify a persisted logical resource.
     */
    private function assertPersistedResource(Model&TranslatableResourceModel $owner): void
    {
        if (! $owner->exists) {
            throw new TranslatableException(
                'Translations can only be written for a persisted owner or grouped resource model.',
            );
        }

        $owner->translationResourceKey();
        $definition = $owner->translationDefinition();

        if ($owner instanceof TranslatableModel
            && $definition instanceof RelatedTranslationDefinition) {
            $translationModel = $owner->translations()->getRelated();

            if ($owner->getConnection()->getName()
                !== $translationModel->getConnection()->getName()) {
                throw new TranslatableException(
                    'Related translation writes require owner and translation models on the same connection.',
                );
            }
        }
    }

    /**
     * Reject fields outside an explicit translation definition.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertDeclaredFields(
        TranslationDefinition $definition,
        array $attributes,
    ): void {
        $undeclared = array_values(array_diff(array_keys($attributes), $definition->fields));

        if ($undeclared !== []) {
            throw InvalidTranslatableFieldException::forField(
                $undeclared[0],
                $definition->fields,
            );
        }
    }
}
