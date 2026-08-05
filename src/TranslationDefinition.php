<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationStorageStrategy;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Defines validated fields, locales, and fallback behavior shared by every translation strategy.
 */
abstract readonly class TranslationDefinition
{
    /**
     * Create a model-level translation definition.
     *
     * @param  list<string>  $fields
     * @param  list<string>|null  $locales
     * @param  list<string>  $fallbackLocales
     */
    public function __construct(
        public array $fields,
        public string $localeKey = 'locale',
        public ?array $locales = null,
        public ?TranslationFallbackPolicy $fallbackPolicy = null,
        public array $fallbackLocales = [],
        public ?bool $fallbackOnNull = null,
        public TranslationMutationPolicy $mutationPolicy = TranslationMutationPolicy::Direct,
    ) {
        $this->assertColumn($this->localeKey, 'locale');
        $this->assertColumns($this->fields, 'translated');
        $this->assertFieldsExclude([$this->localeKey]);

        if ($this->fields === []) {
            throw new TranslatableException('A translation definition must declare at least one translated field.');
        }

        if ($this->locales !== null && $this->locales === []) {
            throw new TranslatableException('Model-specific translation locales cannot be empty.');
        }

        $this->normalizeLocales($this->locales ?? []);
        $this->normalizeLocales($this->fallbackLocales);
    }

    /**
     * Return the persistence strategy represented by this definition.
     */
    abstract public function storage(): TranslationStorageStrategy;

    /**
     * Return the normalized locales supported by this model.
     *
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        $configured = Config::get('translatable.locales', ['en']);

        if (! is_array($configured)) {
            throw new TranslatableException('The translatable.locales configuration value must be an array.');
        }

        $globalLocales = $this->normalizeLocales(array_values($configured));

        if ($globalLocales === []) {
            throw new TranslatableException('At least one supported translation locale must be configured.');
        }

        if ($this->locales === null) {
            return $globalLocales;
        }

        $modelLocales = $this->normalizeLocales($this->locales);
        $unsupported = array_values(array_diff($modelLocales, $globalLocales));

        if ($unsupported !== []) {
            throw new TranslatableException(
                'Model locales must be a subset of translatable.locales: '
                .implode(', ', $unsupported).'.',
            );
        }

        return $modelLocales;
    }

    /**
     * Return the resolved fallback policy for this model.
     */
    public function resolvedFallbackPolicy(): TranslationFallbackPolicy
    {
        if ($this->fallbackPolicy instanceof TranslationFallbackPolicy) {
            return $this->fallbackPolicy;
        }

        $configured = Config::get(
            'translatable.fallback.policy',
            TranslationFallbackPolicy::Configured->value,
        );

        if (! is_string($configured)) {
            throw new TranslatableException('The translatable fallback policy must be a string.');
        }

        return TranslationFallbackPolicy::tryFrom($configured)
            ?? throw new TranslatableException("Unsupported translation fallback policy [{$configured}].");
    }

    /**
     * Determine whether null translated fields may continue through the fallback chain.
     */
    public function shouldFallbackOnNull(): bool
    {
        if ($this->fallbackOnNull !== null) {
            return $this->fallbackOnNull;
        }

        $configured = Config::get('translatable.fallback.on_null', true);

        if (! is_bool($configured)) {
            throw new TranslatableException('The translatable.fallback.on_null value must be boolean.');
        }

        return $configured;
    }

    /**
     * Build the deterministic locale chain for one requested locale.
     *
     * @param  list<string>  $persistedLocales
     * @return list<string>
     */
    public function localeChain(string $requestedLocale, array $persistedLocales = []): array
    {
        $requested = $this->assertLocale($requestedLocale);
        $policy = $this->resolvedFallbackPolicy();

        if ($policy === TranslationFallbackPolicy::ExactOnly) {
            return [$requested];
        }

        $candidates = [
            $requested,
            ...$this->parentLocales($requested),
            ...$this->configuredFallbackLocales(),
        ];

        if ($policy === TranslationFallbackPolicy::AnyAvailable) {
            $available = $persistedLocales !== [] ? $persistedLocales : $this->supportedLocales();
            $normalizedAvailable = $this->normalizeSupportedCandidates($available);
            sort($normalizedAvailable);
            $candidates = [...$candidates, ...$normalizedAvailable];
        }

        return $this->normalizeSupportedCandidates($candidates);
    }

    /**
     * Return the normalized model and global fallback locales.
     *
     * @return list<string>
     */
    public function configuredFallbackLocales(): array
    {
        if ($this->resolvedFallbackPolicy() === TranslationFallbackPolicy::ExactOnly) {
            return [];
        }

        $configuredFallbacks = Config::get('translatable.fallback_locales', []);
        $defaultLocale = Config::get('translatable.default_locale');

        if (! is_array($configuredFallbacks)) {
            throw new TranslatableException(
                'The translatable.fallback_locales configuration value must be an array.',
            );
        }

        if (! is_string($defaultLocale)) {
            throw new TranslatableException(
                'The translatable.default_locale configuration value must be a string.',
            );
        }

        $candidates = [
            ...$this->fallbackLocales,
            ...$this->normalizeLocales(array_values($configuredFallbacks)),
            $defaultLocale,
        ];
        $fallbacks = [];

        foreach ($candidates as $candidate) {
            $normalized = $this->assertLocale($candidate);

            if (! in_array($normalized, $fallbacks, true)) {
                $fallbacks[] = $normalized;
            }
        }

        return $fallbacks;
    }

    /**
     * Return progressively less-specific parents for a normalized locale.
     *
     * @return list<string>
     */
    private function parentLocales(string $locale): array
    {
        $segments = explode('-', $locale);
        $parents = [];

        while (count($segments) > 1) {
            array_pop($segments);
            $parents[] = implode('-', $segments);
        }

        return $parents;
    }

    /**
     * Normalize and assert that a locale is supported by this model.
     *
     * @throws InvalidLocaleException
     */
    public function assertLocale(string $locale): string
    {
        $normalized = (new LocaleCode($locale))->value;
        $supported = $this->supportedLocales();

        if (! in_array($normalized, $supported, true)) {
            throw InvalidLocaleException::unsupported($normalized, $supported);
        }

        return $normalized;
    }

    /**
     * Assert that a field is explicitly translatable.
     *
     * @throws InvalidTranslatableFieldException
     */
    public function assertTranslatableField(string $field): void
    {
        if (! in_array($field, $this->fields, true)) {
            throw InvalidTranslatableFieldException::forField($field, $this->fields);
        }
    }

    /**
     * Normalize locale candidates while discarding unsupported fallbacks.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function normalizeSupportedCandidates(array $candidates): array
    {
        $locales = [];

        foreach ($candidates as $candidate) {
            try {
                $normalized = $this->assertLocale($candidate);
            } catch (InvalidLocaleException) {
                continue;
            }

            if (! in_array($normalized, $locales, true)) {
                $locales[] = $normalized;
            }
        }

        return $locales;
    }

    /**
     * Normalize a list of locale codes and reject duplicates after normalization.
     *
     * @param  list<mixed>  $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $normalized = [];

        foreach ($locales as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException('Every translation locale must be a string.');
            }

            $value = (new LocaleCode($locale))->value;

            if (in_array($value, $normalized, true)) {
                throw new TranslatableException("Duplicate normalized translation locale [{$value}].");
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * Assert that every declared column is safe and unique.
     *
     * @param  list<mixed>  $columns
     */
    protected function assertColumns(array $columns, string $purpose): void
    {
        $seen = [];

        foreach ($columns as $column) {
            if (! is_string($column)) {
                throw new TranslatableException(
                    "Every {$purpose} column must be a string.",
                );
            }

            $this->assertColumn($column, $purpose);
            $normalizedColumn = mb_strtolower($column);

            if (in_array($normalizedColumn, $seen, true)) {
                throw new TranslatableException("Duplicate {$purpose} column [{$column}].");
            }

            $seen[] = $normalizedColumn;
        }
    }

    /**
     * Reject declared fields that overlap persistence identity columns.
     *
     * @param  list<string>  $columns
     */
    protected function assertFieldsExclude(array $columns): void
    {
        $structural = array_map(mb_strtolower(...), $columns);

        foreach ($this->fields as $field) {
            if (in_array(mb_strtolower($field), $structural, true)) {
                throw new TranslatableException(
                    "Structural column [{$field}] cannot be a translated field.",
                );
            }
        }
    }

    /**
     * Return columns whose lifecycle is managed by Eloquent rather than translation payloads.
     *
     * @return list<string>
     */
    protected function modelManagedColumns(Model $model): array
    {
        $columns = [$model->getKeyName()];

        if ($model->usesTimestamps()) {
            $createdAt = $model->getCreatedAtColumn();
            $updatedAt = $model->getUpdatedAtColumn();

            if (is_string($createdAt) && $createdAt !== '') {
                $columns[] = $createdAt;
            }

            if (is_string($updatedAt) && $updatedAt !== '') {
                $columns[] = $updatedAt;
            }
        }

        if (method_exists($model, 'getDeletedAtColumn')) {
            $deletedAt = $model->getDeletedAtColumn();

            if (is_string($deletedAt) && $deletedAt !== '') {
                $columns[] = $deletedAt;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Assert that a declared column uses a safe unqualified identifier.
     */
    protected function assertColumn(string $column, string $purpose): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new TranslatableException("Invalid {$purpose} column [{$column}].");
        }
    }
}
