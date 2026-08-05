<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\TranslationDefinition;

/**
 * Validates and persists translation maps through the model's declared storage strategy.
 */
final readonly class TranslationWriter
{
    /**
     * Create the strategy-aware translation writer.
     */
    public function __construct(
        private LocaleRegistry $locales,
        private RelatedTranslationStore $relatedStore,
        private SelfTranslationStore $selfStore,
        private TranslationPayloadValidator $payloadValidator,
    ) {}

    /**
     * Create or update one locale after filtering values to declared translated fields.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(
        Model&TranslatableResourceModel $owner,
        string $locale,
        array $attributes,
    ): Model {
        $this->assertPersistedResource($owner);
        $definition = $owner->translationDefinition();
        $this->payloadValidator->validate($definition, [$locale => $attributes]);
        $normalizedLocale = $definition->assertLocale(
            $this->locales->assertSupported($locale),
        );
        $this->assertDeclaredFields($definition, $attributes);
        $translation = $this->storeUpsert(
            $owner,
            $definition,
            $normalizedLocale,
            $attributes,
        );
        $this->refreshLoadedTranslations($owner);

        return $translation;
    }

    /**
     * Patch supplied locales while preserving omitted translation rows.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return Collection<int, Model>
     */
    public function patch(
        Model&TranslatableResourceModel $owner,
        array $translations,
    ): Collection {
        $normalizedTranslations = $this->normalizeTranslationMap($owner, $translations);
        $written = new Collection;

        foreach ($normalizedTranslations as $locale => $attributes) {
            $written->push($this->storeUpsert(
                $owner,
                $owner->translationDefinition(),
                $locale,
                $attributes,
            ));
        }

        $this->refreshLoadedTranslations($owner);

        return $written;
    }

    /**
     * Replace all translation rows with the supplied locale set.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return Collection<int, Model>
     */
    public function replace(
        Model&TranslatableResourceModel $owner,
        array $translations,
    ): Collection {
        $normalizedTranslations = $this->normalizeTranslationMap($owner, $translations);
        $written = new Collection;

        foreach ($normalizedTranslations as $locale => $attributes) {
            $written->push($this->storeUpsert(
                $owner,
                $owner->translationDefinition(),
                $locale,
                $attributes,
            ));
        }

        $this->deleteExcept($owner, array_keys($normalizedTranslations));
        $this->refreshLoadedTranslations($owner);

        return $written;
    }

    /**
     * Synchronize translations using an explicit patch or replace contract.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return Collection<int, Model>
     */
    public function sync(
        Model&TranslatableResourceModel $owner,
        array $translations,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): Collection {
        return match ($mode) {
            TranslationSyncMode::Patch => $this->patch($owner, $translations),
            TranslationSyncMode::Replace => $this->replace($owner, $translations),
        };
    }

    /**
     * Delete one exact locale from a logical translatable resource.
     */
    public function delete(
        Model&TranslatableResourceModel $owner,
        string $locale,
    ): bool {
        $this->assertPersistedResource($owner);
        $definition = $owner->translationDefinition();
        $normalizedLocale = $definition->assertLocale(
            $this->locales->assertSupported($locale),
        );
        $deleted = match (true) {
            $owner instanceof TranslatableModel
                && $definition instanceof RelatedTranslationDefinition => $this->relatedStore->delete(
                    $owner,
                    $definition,
                    $normalizedLocale,
                ),
            $owner instanceof SelfTranslatableModel
                && $definition instanceof SelfTranslationDefinition => $this->selfStore->delete(
                    $owner,
                    $definition,
                    $normalizedLocale,
                ),
            default => throw new TranslatableException('The model and translation storage definition do not match.'),
        };

        $this->refreshLoadedTranslations($owner);

        return $deleted;
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

    /**
     * Refresh already-loaded translation state after either strategy writes.
     */
    private function refreshLoadedTranslations(Model&TranslatableResourceModel $owner): void
    {
        if ($owner instanceof TranslatableModel && $owner->relationLoaded('translations')) {
            $owner->load('translations');

            return;
        }

        if ($owner instanceof SelfTranslatableModel && $owner->relationLoaded('translations')) {
            $owner->setRelation('translations', $this->selfStore->rows($owner));
        }
    }
}
