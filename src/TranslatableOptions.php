<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;

/**
 * Defines immutable persistence, field, and fallback behavior for one translatable owner model.
 */
final readonly class TranslatableOptions
{
    /**
     * Create translatable options.
     *
     * @param  class-string<Model>  $translationModel
     * @param  list<string>  $translatableFields
     * @param  list<string>  $availableLocales
     * @param  list<string>  $fallbackLocales
     */
    public function __construct(
        public string $translationModel,
        public ?string $foreignKey = null,
        public string $ownerKey = 'id',
        public string $localeKey = 'locale',
        public array $translatableFields = [],
        public bool $useFallback = true,
        public ?string $fallbackLocale = null,
        public array $availableLocales = [],
        public array $fallbackLocales = [],
        public bool $fallbackOnNull = true,
        public ?TranslationFallbackPolicy $fallbackPolicy = null,
        public TranslationMutationPolicy $mutationPolicy = TranslationMutationPolicy::Direct,
    ) {}

    /**
     * Resolve the translation table's foreign key for an owner table.
     */
    public function getForeignKey(string $tableName): string
    {
        if ($this->foreignKey !== null) {
            return str_replace('{table}', $tableName, $this->foreignKey);
        }

        return Str::singular($tableName).'_id';
    }

    /**
     * Return the normalized deterministic fallback list.
     *
     * @return list<string>
     */
    public function normalizedFallbackLocales(): array
    {
        return $this->toDefinition()->fallbackLocales;
    }

    /**
     * Build the canonical requested, base, configured, default, and available locale chain.
     *
     * @param  list<string>  $availableLocales
     * @return list<string>
     */
    public function localeChain(string $requestedLocale, array $availableLocales = []): array
    {
        return $this->toDefinition()->localeChain($requestedLocale, $availableLocales);
    }

    /**
     * Return normalized model, package, and default fallback locales.
     *
     * @return list<string>
     */
    public function configuredFallbackLocales(): array
    {
        return $this->toDefinition()->configuredFallbackLocales();
    }

    /**
     * Normalize and validate a locale against model-specific supported locales.
     *
     * @throws InvalidLocaleException
     */
    public function assertLocale(string $locale): string
    {
        return $this->toDefinition()->assertLocale($locale);
    }

    /**
     * Assert that a field belongs to this translation definition.
     *
     * @throws InvalidTranslatableFieldException
     */
    public function assertTranslatableField(string $field): void
    {
        $this->toDefinition()->assertTranslatableField($field);
    }

    /**
     * Convert the legacy options object to the canonical related-row definition.
     */
    public function toDefinition(): RelatedTranslationDefinition
    {
        $fallbackLocales = [
            ...$this->fallbackLocales,
            ...($this->fallbackLocale !== null ? [$this->fallbackLocale] : []),
        ];

        return new RelatedTranslationDefinition(
            translationModel: $this->translationModel,
            fields: $this->translatableFields,
            foreignKey: $this->foreignKey,
            ownerKey: $this->ownerKey,
            localeKey: $this->localeKey,
            locales: $this->availableLocales !== [] ? $this->availableLocales : null,
            fallbackPolicy: $this->fallbackPolicy
                ?? ($this->useFallback
                    ? TranslationFallbackPolicy::Configured
                    : TranslationFallbackPolicy::ExactOnly),
            fallbackLocales: array_values(array_unique($fallbackLocales)),
            fallbackOnNull: $this->fallbackOnNull,
            mutationPolicy: $this->mutationPolicy,
        );
    }

    /**
     * Create legacy options for callers that still consume the original public API.
     */
    public static function fromDefinition(RelatedTranslationDefinition $definition): self
    {
        return new self(
            translationModel: $definition->translationModel,
            foreignKey: $definition->foreignKey,
            ownerKey: $definition->ownerKey,
            localeKey: $definition->localeKey,
            translatableFields: $definition->fields,
            useFallback: $definition->resolvedFallbackPolicy() !== TranslationFallbackPolicy::ExactOnly,
            availableLocales: $definition->locales ?? [],
            fallbackLocales: $definition->fallbackLocales,
            fallbackOnNull: $definition->shouldFallbackOnNull(),
            fallbackPolicy: $definition->resolvedFallbackPolicy(),
            mutationPolicy: $definition->mutationPolicy,
        );
    }
}
