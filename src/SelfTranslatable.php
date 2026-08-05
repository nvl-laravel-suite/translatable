<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\SelfTranslationStore;
use Nvl\Translatable\Services\TranslationPayloadValidator;
use Nvl\Translatable\Services\TranslationResolver;

/**
 * Adds grouped same-table translation behavior to an Eloquent model.
 *
 * @method static Builder<static> locale(?string $locale = null)
 * @method static Builder<static> withResolvedTranslations(?string $locale = null)
 * @method static Builder<static> withAllTranslations(?string $groupValue = null)
 * @method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = null, ?string $locale = null)
 * @method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
 * @method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
 * @method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
 *
 * @mixin Model
 */
trait SelfTranslatable
{
    private ?SelfTranslationDefinition $resolvedTranslationDefinition = null;

    private ?SelfTranslatableOptions $resolvedLegacyTranslationOptions = null;

    protected ?string $currentLocale = null;

    /**
     * Boot creation-time structural validation for self-translated rows.
     */
    protected static function bootSelfTranslatable(): void
    {
        static::creating(function (Model $model): void {
            self::prepareSelfTranslationForCreation($model);
        });

        static::updating(function (Model $model): void {
            self::assertSelfTranslationIdentityIsImmutable($model);
        });
    }

    /**
     * Enforce structural creation invariants even when model events are muted.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        self::prepareSelfTranslationForCreation($this);

        return parent::performInsert($query);
    }

    /**
     * Enforce identity immutability even when model events are muted.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        self::assertSelfTranslationIdentityIsImmutable($this);

        return parent::performUpdate($query);
    }

    /**
     * Revalidate row structure after every creating listener has run.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert(): array
    {
        self::prepareSelfTranslationForCreation($this);

        return $this->getAttributes();
    }

    /**
     * Revalidate identity immediately before an instance-level update is built.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        self::assertSelfTranslationIdentityIsImmutable($this);
        parent::setKeysForSaveQuery($query);

        return $query;
    }

    /**
     * Validate and normalize a self-translated row before insertion.
     */
    private static function prepareSelfTranslationForCreation(Model $model): void
    {
        if (! method_exists($model, 'translationDefinition')
            || ! method_exists($model, 'getCurrentLocale')) {
            throw new TranslatableException(
                'Self-translatable models must use the complete SelfTranslatable trait.',
            );
        }

        $definition = $model->translationDefinition();

        if (! $definition instanceof SelfTranslationDefinition) {
            throw new TranslatableException(
                'A self-translatable model must return a SelfTranslationDefinition.',
            );
        }

        $groupValue = $model->getAttribute($definition->groupKey);

        if ($groupValue === null || $groupValue === '') {
            throw new TranslatableException(
                "Self-translatable rows require group column [{$definition->groupKey}].",
            );
        }

        $locale = $model->getAttribute($definition->localeKey);
        $resolvedLocale = is_string($locale) && $locale !== ''
            ? $definition->assertLocale($locale)
            : $model->getCurrentLocale();

        $model->setAttribute($definition->localeKey, $resolvedLocale);
    }

    /**
     * Reject changes to the logical identity of a persisted locale row.
     */
    private static function assertSelfTranslationIdentityIsImmutable(Model $model): void
    {
        if (! method_exists($model, 'translationDefinition')) {
            throw new TranslatableException(
                'Self-translatable models must use the complete SelfTranslatable trait.',
            );
        }

        $definition = $model->translationDefinition();

        if (! $definition instanceof SelfTranslationDefinition) {
            throw new TranslatableException(
                'A self-translatable model must return a SelfTranslationDefinition.',
            );
        }

        if ($model->isDirty([$definition->groupKey, $definition->localeKey])) {
            throw new TranslatableException(
                'Self-translation group and locale columns are immutable after creation.',
            );
        }
    }

    /**
     * Define translation persistence and fallback behavior for this model.
     */
    protected function defineTranslations(): SelfTranslationDefinition|SelfTranslatableOptions
    {
        return $this->selfTranslatableOptions();
    }

    /**
     * Return legacy self-row options when a model has not migrated its declaration yet.
     */
    protected function selfTranslatableOptions(): SelfTranslatableOptions
    {
        throw new TranslatableException(
            sprintf('%s must implement defineTranslations().', static::class),
        );
    }

    /**
     * Return the cached immutable self-row translation definition.
     */
    public function translationDefinition(): SelfTranslationDefinition
    {
        if ($this->resolvedTranslationDefinition instanceof SelfTranslationDefinition) {
            return $this->resolvedTranslationDefinition;
        }

        $definition = $this->resolveTranslationDefinition(
            $this->defineTranslations(),
        );
        $definition->assertModel($this);

        return $this->resolvedTranslationDefinition = $definition;
    }

    /**
     * Return a compatibility view of the model's self-row translation definition.
     */
    public function translationOptions(): SelfTranslatableOptions
    {
        return $this->resolvedLegacyTranslationOptions
            ??= SelfTranslatableOptions::fromDefinition($this->translationDefinition());
    }

    /**
     * Normalize the typed declaration or its legacy compatibility adapter.
     */
    private function resolveTranslationDefinition(
        SelfTranslationDefinition|SelfTranslatableOptions $definition,
    ): SelfTranslationDefinition {
        return $definition instanceof SelfTranslatableOptions
            ? $definition->toDefinition()
            : $definition;
    }

    /**
     * Scope a query to one preferred locale row per logical resource group.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLocale(Builder $query, ?string $locale = null): Builder
    {
        $definition = $this->translationDefinition();
        $requested = $definition->assertLocale($locale ?? $this->getCurrentLocale());
        $candidates = $definition->localeChain($requested);
        $table = $this->getTable();
        $groupKey = $definition->groupKey;
        $localeKey = $definition->localeKey;

        return $query->where(function (Builder $preferenceQuery) use (
            $candidates,
            $table,
            $groupKey,
            $localeKey,
        ): void {
            foreach ($candidates as $index => $candidate) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $preferredLocales = array_slice($candidates, 0, $index);

                $preferenceQuery->{$method}(function (Builder $candidateQuery) use (
                    $candidate,
                    $preferredLocales,
                    $table,
                    $groupKey,
                    $localeKey,
                    $index,
                ): void {
                    $candidateQuery->getQuery()->where(
                        "{$table}.{$localeKey}",
                        $candidate,
                    );

                    if ($preferredLocales === []) {
                        return;
                    }

                    $alias = "preferred_translation_{$index}";
                    $candidateQuery->getQuery()->whereNotExists(
                        static function (QueryBuilder $subquery) use (
                            $alias,
                            $preferredLocales,
                            $table,
                            $groupKey,
                            $localeKey,
                        ): void {
                            $subquery
                                ->selectRaw('1')
                                ->from("{$table} as {$alias}")
                                ->whereColumn("{$alias}.{$groupKey}", "{$table}.{$groupKey}")
                                ->whereIn("{$alias}.{$localeKey}", $preferredLocales);
                        },
                    );
                });
            }
        });
    }

    /**
     * Scope a query to a specific logical translation group.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTranslationGroup(Builder $query, int|string $groupValue): Builder
    {
        $definition = $this->translationDefinition();
        $query->getQuery()->where($definition->groupKey, $groupValue);

        return $query;
    }

    /**
     * Scope a query to the resolved locale rows used for public reads.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithResolvedTranslations(Builder $query, ?string $locale = null): Builder
    {
        return $this->scopeLocale($query, $locale);
    }

    /**
     * Optionally scope a query to all locale rows in one logical group.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithAllTranslations(
        Builder $query,
        int|string|null $groupValue = null,
    ): Builder {
        if ($groupValue === null) {
            return $query;
        }

        return $this->scopeTranslationGroup($query, $groupValue);
    }

    /**
     * Return the current content locale for this model instance.
     */
    public function getCurrentLocale(): string
    {
        $definition = $this->translationDefinition();

        if ($this->currentLocale !== null) {
            return $definition->assertLocale($this->currentLocale);
        }

        $contextLocale = Context::get(ContentLocale::CONTEXT_KEY);
        $configuredFallback = Config::get('app.fallback_locale');
        $candidates = [
            ...(is_string($contextLocale) ? [$contextLocale] : []),
            App::getLocale(),
            ...$definition->configuredFallbackLocales(),
            ...(is_string($configuredFallback) ? [$configuredFallback] : []),
            ...$definition->supportedLocales(),
        ];

        foreach ($candidates as $candidate) {
            try {
                return $definition->assertLocale($candidate);
            } catch (InvalidLocaleException) {
                continue;
            }
        }

        return $definition->supportedLocales()[0];
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
     * Return the configured locale column.
     */
    public function getLocaleKey(): string
    {
        return $this->translationDefinition()->localeKey;
    }

    /**
     * Return the stable logical group identifier used by translation tooling.
     */
    public function translationResourceKey(): int|string
    {
        $definition = $this->translationDefinition();
        $groupValue = $this->getAttribute($definition->groupKey);

        if (! is_int($groupValue) && ! is_string($groupValue)) {
            throw new TranslatableException(
                "Self-translatable rows require a string or integer [{$definition->groupKey}] value.",
            );
        }

        return $groupValue;
    }

    /**
     * Return all persisted locale rows for this logical resource.
     *
     * @return Collection<int, covariant Model>
     */
    public function getAllTranslations(): Collection
    {
        $definition = $this->translationDefinition();
        $groupValue = $this->translationResourceKey();

        if ($this->relationLoaded('translations')) {
            $rows = $this->getRelation('translations');

            if ($rows instanceof Collection) {
                return $rows;
            }
        }

        return static::query()
            ->where($definition->groupKey, $groupValue)
            ->orderBy($definition->localeKey)
            ->get();
    }

    /**
     * Return the locale row selected by the deterministic fallback policy.
     */
    public function getTranslation(?string $locale = null, bool $withFallback = true): ?Model
    {
        $definition = $this->translationDefinition();
        $rows = $this->getAllTranslations();
        $requested = $definition->assertLocale($locale ?? $this->getCurrentLocale());
        $localeChain = $withFallback
            ? $definition->localeChain($requested, $this->persistedLocales($rows))
            : [$requested];

        foreach ($localeChain as $candidate) {
            $row = $rows->firstWhere($definition->localeKey, $candidate);

            if ($row instanceof Model) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Resolve one translated field and include locale provenance.
     */
    public function resolveTranslation(string $field, ?string $locale = null): TranslationResolution
    {
        $definition = $this->translationDefinition();
        $definition->assertTranslatableField($field);
        $rows = $this->getAllTranslations();
        $requested = $definition->assertLocale($locale ?? $this->getCurrentLocale());
        $localeChain = $definition->localeChain($requested, $this->persistedLocales($rows));

        return (new TranslationResolver)->resolve(
            translations: $rows,
            options: $definition,
            field: $field,
            requestedLocale: $requested,
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
     * Determine whether a field belongs to this model's translation definition.
     */
    public function isTranslatableAttribute(string $field): bool
    {
        return in_array($field, $this->translationDefinition()->fields, true);
    }

    /**
     * Determine whether an exact locale row exists in this logical resource.
     */
    public function hasTranslation(?string $locale = null): bool
    {
        $definition = $this->translationDefinition();
        $resolvedLocale = $definition->assertLocale($locale ?? $this->getCurrentLocale());

        return static::query()
            ->where($definition->groupKey, $this->translationResourceKey())
            ->where($definition->localeKey, $resolvedLocale)
            ->exists();
    }

    /**
     * Return every normalized persisted locale in this logical resource.
     *
     * @return list<string>
     */
    public function getAvailableLocales(): array
    {
        return $this->persistedLocales($this->getAllTranslations());
    }

    /**
     * Filter logical resources by one exact translated field and locale.
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
        $definition = $this->translationDefinition();
        $definition->assertTranslatableField($field);
        $resolvedLocale = $definition->assertLocale($locale ?? $this->getCurrentLocale());

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $allowedOperators = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];
        $normalizedOperator = is_string($operator) ? mb_strtolower($operator) : '';

        if (! in_array($normalizedOperator, $allowedOperators, true)) {
            throw new TranslatableException(
                "The translation comparison operator [{$normalizedOperator}] is not allowed.",
            );
        }

        return $query
            ->where($definition->localeKey, $resolvedLocale)
            ->where($field, $normalizedOperator, $value);
    }

    /**
     * Filter exact locale rows whose translated field is null.
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

        return $query
            ->where($definition->localeKey, $resolvedLocale)
            ->whereNull($field);
    }

    /**
     * Filter exact locale rows whose translated field is non-null.
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

        return $query
            ->where($definition->localeKey, $resolvedLocale)
            ->whereNotNull($field);
    }

    /**
     * Order logical resources by one exact translated field and locale.
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
        $definition = $this->translationDefinition();
        $definition->assertTranslatableField($field);
        $normalizedDirection = mb_strtolower($direction);

        if (! in_array($normalizedDirection, ['asc', 'desc'], true)) {
            throw new TranslatableException(
                "The translation sort direction [{$direction}] is not allowed.",
            );
        }

        return $this->scopeLocale($query, $locale)
            ->orderBy($field, $normalizedDirection);
    }

    /**
     * Create or update a locale row using only explicitly translated fields.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function setTranslation(array $data, ?string $locale = null): Model
    {
        $this->assertPersistedSelfTranslation();
        $definition = $this->translationDefinition();
        $resolvedLocale = $definition->assertLocale($locale ?? $this->getCurrentLocale());
        $attributes = [];

        foreach ($data as $field => $value) {
            if (! is_string($field)) {
                throw new TranslatableException('Translated field names must be strings.');
            }

            $definition->assertTranslatableField($field);
            $attributes[$field] = $value;
        }

        app(TranslationPayloadValidator::class)->validate(
            $definition,
            [$resolvedLocale => $attributes],
        );

        return $this->getConnection()->transaction(
            function () use ($definition, $resolvedLocale, $attributes): Model {
                $store = new SelfTranslationStore;
                $translation = $store->upsert(
                    $this,
                    $definition,
                    $resolvedLocale,
                    $attributes,
                );
                $this->refreshLoadedSelfTranslations($store);

                return $translation;
            },
            $this->translationTransactionAttempts(),
        );
    }

    /**
     * Delete one locale row while preserving the required last translation.
     */
    public function deleteTranslation(?string $locale = null): bool
    {
        $this->assertPersistedSelfTranslation();
        $definition = $this->translationDefinition();
        $resolvedLocale = $definition->assertLocale($locale ?? $this->getCurrentLocale());

        return $this->getConnection()->transaction(
            function () use ($definition, $resolvedLocale): bool {
                static::query()
                    ->where($definition->groupKey, $this->translationResourceKey())
                    ->orderBy($this->getKeyName())
                    ->lockForUpdate()
                    ->get();

                $store = new SelfTranslationStore;
                $deleted = $store->delete(
                    $this,
                    $definition,
                    $resolvedLocale,
                );
                $this->refreshLoadedSelfTranslations($store);

                return $deleted;
            },
            $this->translationTransactionAttempts(),
        );
    }

    /**
     * Clone declared translated fields into another locale.
     */
    public function cloneTranslation(string $fromLocale, string $toLocale): ?Model
    {
        $this->assertPersistedSelfTranslation();
        $definition = $this->translationDefinition();
        $sourceLocale = $definition->assertLocale($fromLocale);
        $targetLocale = $definition->assertLocale($toLocale);

        return $this->getConnection()->transaction(
            function () use ($definition, $sourceLocale, $targetLocale): ?Model {
                $source = static::query()
                    ->where($definition->groupKey, $this->translationResourceKey())
                    ->where($definition->localeKey, $sourceLocale)
                    ->lockForUpdate()
                    ->first();

                if (! $source instanceof Model) {
                    return null;
                }

                $data = [];

                foreach ($definition->fields as $field) {
                    $data[$field] = $source->getAttribute($field);
                }

                app(TranslationPayloadValidator::class)->validate(
                    $definition,
                    [$targetLocale => $data],
                );

                $store = new SelfTranslationStore;
                $translation = $store->upsert(
                    $this,
                    $definition,
                    $targetLocale,
                    $data,
                );
                $this->refreshLoadedSelfTranslations($store);

                return $translation;
            },
            $this->translationTransactionAttempts(),
        );
    }

    /**
     * Reject convenience mutations for an unsaved representative row.
     */
    private function assertPersistedSelfTranslation(): void
    {
        if (! $this->exists) {
            throw new TranslatableException(
                'Self translations can only be mutated through a persisted representative row.',
            );
        }

        $this->translationResourceKey();
    }

    /**
     * Return the configured deadlock retry count for convenience mutations.
     *
     * @return positive-int
     */
    private function translationTransactionAttempts(): int
    {
        $attempts = Config::get('translatable.transactions.attempts', 3);

        if (! is_int($attempts) || $attempts < 1) {
            throw new TranslatableException(
                'The translatable.transactions.attempts value must be a positive integer.',
            );
        }

        return $attempts;
    }

    /**
     * Refresh centrally preloaded grouped rows after a convenience mutation.
     */
    private function refreshLoadedSelfTranslations(SelfTranslationStore $store): void
    {
        if ($this->relationLoaded('translations')) {
            $this->setRelation('translations', $store->rows($this));
        }
    }

    /**
     * Return normalized persisted locales from self-translation rows.
     *
     * @param  Collection<int, covariant Model>  $rows
     * @return list<string>
     */
    private function persistedLocales(Collection $rows): array
    {
        $definition = $this->translationDefinition();
        $locales = [];

        foreach ($rows as $row) {
            $locale = $row->getAttribute($definition->localeKey);

            if (! is_string($locale)) {
                continue;
            }

            try {
                $normalized = $definition->assertLocale($locale);
            } catch (InvalidLocaleException) {
                continue;
            }

            if ($locale !== $normalized) {
                continue;
            }

            if (! in_array($normalized, $locales, true)) {
                $locales[] = $normalized;
            }
        }

        sort($locales);

        return $locales;
    }
}
