<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\TranslationResolver;

/**
 * Adds explicit translation relationships, resolution, and query scopes to an Eloquent model.
 *
 * @property-read Collection<int, Model> $translations
 *
 * @method static Builder<static> withAllTranslations()
 * @method static Builder<static> withResolvedTranslations(?string $locale = null)
 * @method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = null, ?string $locale = null)
 * @method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
 * @method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
 * @method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
 *
 * @mixin Model
 */
trait Translatable
{
    private ?RelatedTranslationDefinition $resolvedTranslationDefinition = null;

    private ?TranslatableOptions $resolvedLegacyTranslationOptions = null;

    protected ?string $currentLocale = null;

    /**
     * Define translation persistence and fallback behavior for this model.
     */
    protected function defineTranslations(): RelatedTranslationDefinition|TranslatableOptions
    {
        return $this->translatableOptions();
    }

    /**
     * Return legacy related-row options when a model has not migrated its declaration yet.
     */
    protected function translatableOptions(): TranslatableOptions
    {
        throw new Exceptions\TranslatableException(
            sprintf('%s must implement defineTranslations().', static::class),
        );
    }

    /**
     * Return the cached immutable related-row translation definition.
     */
    public function translationDefinition(): RelatedTranslationDefinition
    {
        if ($this->resolvedTranslationDefinition instanceof RelatedTranslationDefinition) {
            return $this->resolvedTranslationDefinition;
        }

        $definition = $this->resolveTranslationDefinition(
            $this->defineTranslations(),
        );
        $definition->assertModel($this);

        return $this->resolvedTranslationDefinition = $definition;
    }

    /**
     * Return a compatibility view of the model's related-row translation definition.
     */
    public function translationOptions(): TranslatableOptions
    {
        return $this->resolvedLegacyTranslationOptions
            ??= TranslatableOptions::fromDefinition($this->translationDefinition());
    }

    /**
     * Normalize the typed declaration or its legacy compatibility adapter.
     */
    private function resolveTranslationDefinition(
        RelatedTranslationDefinition|TranslatableOptions $definition,
    ): RelatedTranslationDefinition {
        return $definition instanceof TranslatableOptions
            ? $definition->toDefinition()
            : $definition;
    }

    /**
     * Define the one-to-many relationship containing every locale row.
     *
     * @return HasMany<Model, $this>
     */
    public function translations(): HasMany
    {
        $options = $this->translationDefinition();

        return $this->hasMany(
            $options->translationModel,
            $options->foreignKey($this->getTable()),
            $options->ownerKey,
        );
    }

    /**
     * Define a one-to-one relationship for one exact locale.
     *
     * @return HasOne<Model, $this>
     */
    public function translation(?string $locale = null): HasOne
    {
        $options = $this->translationDefinition();
        $resolvedLocale = $options->assertLocale($locale ?? $this->getCurrentLocale());

        return $this->hasOne(
            $options->translationModel,
            $options->foreignKey($this->getTable()),
            $options->ownerKey,
        )->where($options->localeKey, $resolvedLocale);
    }

    /**
     * Return the first translation row from the deterministic locale chain.
     */
    public function getTranslation(?string $locale = null, bool $withFallback = true): ?Model
    {
        $options = $this->translationDefinition();
        $translations = $this->translationRows();
        $requestedLocale = $options->assertLocale($locale ?? $this->getCurrentLocale());
        $localeChain = $withFallback
            ? $options->localeChain($requestedLocale, $this->persistedLocales($translations))
            : [$requestedLocale];

        foreach ($localeChain as $candidateLocale) {
            $translation = $translations->firstWhere($options->localeKey, $candidateLocale);

            if ($translation instanceof Model) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * Return the current content locale for this model instance.
     */
    public function getCurrentLocale(): string
    {
        $options = $this->translationDefinition();

        if ($this->currentLocale !== null) {
            return $options->assertLocale($this->currentLocale);
        }

        $contextLocale = Context::get(ContentLocale::CONTEXT_KEY);
        $configuredFallback = Config::get('app.fallback_locale', 'en');
        $candidates = [
            ...(is_string($contextLocale) ? [$contextLocale] : []),
            App::getLocale(),
            ...$options->configuredFallbackLocales(),
            ...(is_string($configuredFallback) ? [$configuredFallback] : []),
            ...$options->supportedLocales(),
        ];

        foreach ($candidates as $candidate) {
            try {
                return $options->assertLocale($candidate);
            } catch (Exceptions\InvalidLocaleException) {
                continue;
            }
        }

        return $options->supportedLocales()[0];
    }

    /**
     * Override the content locale for this model instance.
     */
    public function setLocale(string $locale): self
    {
        $this->currentLocale = $this->translationDefinition()->assertLocale($locale);

        return $this;
    }

    /**
     * Determine whether a field belongs to this model's translation definition.
     */
    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translationDefinition()->fields, true);
    }

    /**
     * Resolve one translated field and include locale provenance.
     */
    public function resolveTranslation(string $field, ?string $locale = null): TranslationResolution
    {
        $options = $this->translationDefinition();
        $options->assertTranslatableField($field);
        $translations = $this->translationRows();
        $requestedLocale = $options->assertLocale($locale ?? $this->getCurrentLocale());
        $localeChain = $options->localeChain(
            $requestedLocale,
            $this->persistedLocales($translations),
        );

        return (new TranslationResolver)->resolve(
            translations: $translations,
            options: $options,
            field: $field,
            requestedLocale: $requestedLocale,
            localeChain: $localeChain,
        );
    }

    /**
     * Return one translated field for the requested locale.
     */
    public function translated(string $field, ?string $locale = null): mixed
    {
        return $this->resolveTranslation($field, $locale)->value;
    }

    /**
     * Return all declared translated fields resolved through one locale chain.
     *
     * @return array<string, mixed>
     */
    public function getTranslatedAttributes(?string $locale = null): array
    {
        return collect($this->translationDefinition()->fields)
            ->mapWithKeys(fn (string $field): array => [
                $field => $this->translated($field, $locale),
            ])
            ->all();
    }

    /**
     * Return every translation row, loading the relation only when needed.
     *
     * @return Collection<int, Model>
     */
    public function getAllTranslations(): Collection
    {
        return $this->translationRows();
    }

    /**
     * Return the owner key used by centralized translation tooling.
     */
    public function translationResourceKey(): int|string
    {
        $key = $this->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new Exceptions\TranslatableException(
                'A translatable owner key must be a string or integer.',
            );
        }

        return $key;
    }

    /**
     * Eager-load translation rows required for deterministic resolution.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithResolvedTranslations(Builder $query, ?string $locale = null): Builder
    {
        $this->translationDefinition()->assertLocale($locale ?? $this->getCurrentLocale());

        return $query->with('translations');
    }

    /**
     * Eager-load every translation row for administrative editing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithAllTranslations(Builder $query): Builder
    {
        return $query->with('translations');
    }

    /**
     * Filter owners by one validated translated field and exact locale.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereTranslated(
        Builder $query,
        string $field,
        mixed $operator,
        mixed $value = null,
        ?string $locale = null,
    ): Builder {
        $options = $this->translationDefinition();
        $options->assertTranslatableField($field);
        $resolvedLocale = $options->assertLocale($locale ?? $this->getCurrentLocale());

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $allowedOperators = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];
        $normalizedOperator = is_string($operator) ? mb_strtolower($operator) : '';

        if (! in_array($normalizedOperator, $allowedOperators, true)) {
            throw new Exceptions\TranslatableException(
                "The translation comparison operator [{$normalizedOperator}] is not allowed.",
            );
        }

        return $query->whereHas(
            'translations',
            static fn (Builder $translationQuery): Builder => $translationQuery
                ->where($options->localeKey, $resolvedLocale)
                ->where($field, $normalizedOperator, $value),
        );
    }

    /**
     * Filter owners whose exact locale row contains a null translated field.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereTranslationNull(
        Builder $query,
        string $field,
        ?string $locale = null,
    ): Builder {
        $definition = $this->translationDefinition();
        $definition->assertTranslatableField($field);
        $resolvedLocale = $definition->assertLocale($locale ?? $this->getCurrentLocale());

        return $query->whereHas(
            'translations',
            static fn (Builder $translationQuery): Builder => $translationQuery
                ->where($definition->localeKey, $resolvedLocale)
                ->whereNull($field),
        );
    }

    /**
     * Filter owners whose exact locale row contains a non-null translated field.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereTranslationNotNull(
        Builder $query,
        string $field,
        ?string $locale = null,
    ): Builder {
        $definition = $this->translationDefinition();
        $definition->assertTranslatableField($field);
        $resolvedLocale = $definition->assertLocale($locale ?? $this->getCurrentLocale());

        return $query->whereHas(
            'translations',
            static fn (Builder $translationQuery): Builder => $translationQuery
                ->where($definition->localeKey, $resolvedLocale)
                ->whereNotNull($field),
        );
    }

    /**
     * Order owners by one validated translated field and exact locale.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderByTranslated(
        Builder $query,
        string $field,
        string $direction = 'asc',
        ?string $locale = null,
    ): Builder {
        $options = $this->translationDefinition();
        $options->assertTranslatableField($field);
        $resolvedLocale = $options->assertLocale($locale ?? $this->getCurrentLocale());
        $normalizedDirection = mb_strtolower($direction);

        if (! in_array($normalizedDirection, ['asc', 'desc'], true)) {
            throw new Exceptions\TranslatableException(
                "The translation sort direction [{$direction}] is not allowed.",
            );
        }

        $translationModel = new $options->translationModel;
        $translationTable = $translationModel->getTable();
        $ownerTable = $this->getTable();
        $foreignKey = $options->foreignKey($ownerTable);

        $translationValueQuery = $translationModel->newQuery()
            ->select($field)
            ->whereColumn(
                "{$translationTable}.{$foreignKey}",
                "{$ownerTable}.{$options->ownerKey}",
            )
            ->where($options->localeKey, $resolvedLocale)
            ->limit(1);

        return $query->orderBy($translationValueQuery, $normalizedDirection);
    }

    /**
     * Determine whether an exact locale translation exists.
     */
    public function hasTranslation(?string $locale = null): bool
    {
        $options = $this->translationDefinition();
        $resolvedLocale = $options->assertLocale($locale ?? $this->getCurrentLocale());

        if ($this->relationLoaded('translations')) {
            return $this->translationRows()->contains(
                $options->localeKey,
                $resolvedLocale,
            );
        }

        return $this->translations()
            ->where($options->localeKey, $resolvedLocale)
            ->exists();
    }

    /**
     * Return every locale with a persisted translation row.
     *
     * @return list<string>
     */
    public function getAvailableLocales(): array
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translationRows()
            : $this->translations()->get();

        return $this->persistedLocales($translations);
    }

    /**
     * Return translation rows for a locale chain without querying when the relation is loaded.
     *
     * @return Collection<int, Model>
     */
    private function translationRows(): Collection
    {
        if ($this->relationLoaded('translations')) {
            $translations = $this->getRelation('translations');

            if ($translations instanceof Collection) {
                return $translations;
            }
        }

        return $this->translations()->get();
    }

    /**
     * Return normalized persisted locales from a translation-row collection.
     *
     * @param  Collection<int, Model>  $translations
     * @return list<string>
     */
    private function persistedLocales(Collection $translations): array
    {
        $localeKey = $this->translationDefinition()->localeKey;
        $available = [];

        foreach ($translations as $translation) {
            $locale = $translation->getAttribute($localeKey);

            if (! is_string($locale) || $locale === '') {
                continue;
            }

            try {
                $normalized = $this->translationDefinition()->assertLocale($locale);
            } catch (Exceptions\InvalidLocaleException) {
                continue;
            }

            if ($locale !== $normalized) {
                continue;
            }

            if (! in_array($normalized, $available, true)) {
                $available[] = $normalized;
            }
        }

        sort($available);

        return $available;
    }
}
