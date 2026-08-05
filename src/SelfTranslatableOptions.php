<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;

/**
 * Legacy configuration adapter for self-translatable models.
 *
 * Used when translations are stored in the same table as rows
 * with different locale values, rather than in separate tables.
 */
final readonly class SelfTranslatableOptions
{
    /**
     * Create self-translatable configuration.
     *
     * @param  string  $localeKey  Column that stores locale code
     * @param  string  $groupKey  Column that groups translations (e.g., 'slug', 'handle')
     * @param  bool  $useFallback  Whether to use fallback locale
     * @param  string|null  $fallbackLocale  Default fallback locale (null uses config value)
     * @param  list<string>  $availableLocales  List of available locales
     * @param  list<string>  $translatableFields  Fields that contain translatable content
     * @param  list<string>  $fallbackLocales  Ordered model-specific fallback locales
     * @param  list<string>  $sharedFields  Structural fields copied into newly created locale rows
     */
    public function __construct(
        public string $localeKey = 'locale',
        public string $groupKey = 'slug',
        public bool $useFallback = true,
        public ?string $fallbackLocale = null,
        public array $availableLocales = [],
        public array $translatableFields = [],
        public array $fallbackLocales = [],
        public bool $fallbackOnNull = true,
        public array $sharedFields = [],
        public bool $allowDeletingLastTranslation = false,
        public ?TranslationFallbackPolicy $fallbackPolicy = null,
        public TranslationMutationPolicy $mutationPolicy = TranslationMutationPolicy::Direct,
    ) {}

    /**
     * Convert the legacy options object to the canonical self-row definition.
     */
    public function toDefinition(): SelfTranslationDefinition
    {
        $fallbackLocales = [
            ...$this->fallbackLocales,
            ...($this->fallbackLocale !== null ? [$this->fallbackLocale] : []),
        ];

        return new SelfTranslationDefinition(
            groupKey: $this->groupKey,
            fields: $this->translatableFields,
            sharedFields: $this->sharedFields,
            localeKey: $this->localeKey,
            locales: $this->availableLocales !== [] ? $this->availableLocales : null,
            fallbackPolicy: $this->fallbackPolicy
                ?? ($this->useFallback
                    ? TranslationFallbackPolicy::Configured
                    : TranslationFallbackPolicy::ExactOnly),
            fallbackLocales: array_values(array_unique($fallbackLocales)),
            fallbackOnNull: $this->fallbackOnNull,
            allowDeletingLastTranslation: $this->allowDeletingLastTranslation,
            mutationPolicy: $this->mutationPolicy,
        );
    }

    /**
     * Create legacy options for callers that still consume the original public API.
     */
    public static function fromDefinition(SelfTranslationDefinition $definition): self
    {
        return new self(
            localeKey: $definition->localeKey,
            groupKey: $definition->groupKey,
            useFallback: $definition->resolvedFallbackPolicy() !== TranslationFallbackPolicy::ExactOnly,
            availableLocales: $definition->locales ?? [],
            translatableFields: $definition->fields,
            fallbackLocales: $definition->fallbackLocales,
            fallbackOnNull: $definition->shouldFallbackOnNull(),
            sharedFields: $definition->sharedFields,
            allowDeletingLastTranslation: $definition->allowDeletingLastTranslation,
            fallbackPolicy: $definition->resolvedFallbackPolicy(),
            mutationPolicy: $definition->mutationPolicy,
        );
    }
}
