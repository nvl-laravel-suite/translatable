<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Event;
use LogicException;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationCoverageData;
use Nvl\Translatable\Data\TranslationResourceRecordData;
use Nvl\Translatable\Data\TranslationResourceSummaryData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Events\TranslationResourcesGathered;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\TranslationResourceDefinition;
use Nvl\Translatable\TranslationResourceQuery;
use UnitEnum;

/**
 * Gathers normalized logical resources and locale coverage across both storage strategies.
 */
final readonly class TranslationResourceGatherer
{
    /**
     * Create the centralized translation gatherer.
     */
    public function __construct(
        private TranslationResourceRegistry $resources,
        private LocaleRegistry $locales,
        private TranslationResourceAuthorization $authorization,
        private TranslationResourceVersioner $versioner,
        private TranslationResourceLocator $locator,
    ) {}

    /**
     * Return metadata and locale coverage for every registered resource.
     *
     * @return list<TranslationResourceSummaryData>
     */
    public function summaries(TranslationActorData $actor): array
    {
        return array_map(function (TranslationResourceDefinition $resource) use ($actor): TranslationResourceSummaryData {
            $this->authorization->authorize($actor, TranslationResourceAbility::Report, $resource);
            $metadata = $resource->metadata($this->locales->supported());
            $model = $resource->newModel();
            $available = $this->isAvailable($model);

            if (! $available) {
                return new TranslationResourceSummaryData(
                    ...$metadata,
                    available: false,
                    total: 0,
                    coverage: [],
                );
            }

            $total = $this->logicalTotal($resource);
            $coverage = [];

            foreach ($metadata['locales'] as $locale) {
                $translated = $this->translatedTotal($resource, $model, $locale);
                $coverage[$locale] = new TranslationCoverageData(
                    translated: $translated,
                    missing: max(0, $total - $translated),
                );
            }

            return new TranslationResourceSummaryData(
                ...$metadata,
                available: true,
                total: $total,
                coverage: $coverage,
            );
        }, $this->resources->all());
    }

    /**
     * Gather one page of normalized logical records from a registered resource.
     *
     * @return LengthAwarePaginator<int, TranslationResourceRecordData>
     */
    public function gather(
        string $resourceKey,
        TranslationActorData $actor,
        ?TranslationResourceQuery $query = null,
    ): LengthAwarePaginator {
        $resource = $this->resources->get($resourceKey);
        $query ??= new TranslationResourceQuery;
        $model = $resource->newModel();
        $this->authorization->authorize($actor, TranslationResourceAbility::List, $resource);
        $this->assertAvailable($resource, $model);
        $builder = $this->locator->query($resource);

        if ($query->perPage > $resource->maximumPageSize) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$resourceKey}] page size exceeds {$resource->maximumPageSize}.",
            );
        }

        $this->applySearch($builder, $resource, $model, $query->search);
        $this->applyMissingLocale($builder, $model, $query->missingLocale);
        $definition = $model->translationDefinition();
        $defaultOrderColumn = $definition instanceof SelfTranslationDefinition
            ? $definition->groupKey
            : $model->getKeyName();
        $orderColumn = $resource->orderColumn ?? $defaultOrderColumn;
        $builder->orderBy($orderColumn);

        if (mb_strtolower($orderColumn) !== mb_strtolower($defaultOrderColumn)) {
            $builder->orderBy($defaultOrderColumn);
        }

        $paginator = $builder->paginate(
            perPage: $query->perPage,
            pageName: 'page',
            page: $query->page,
        );
        $records = new EloquentCollection($paginator->items());
        $this->locator->loadTranslations($records);
        $paginator = $paginator->through(
            fn (Model $record): TranslationResourceRecordData => $this->serialize($resource, $record),
        );

        Event::dispatch(new TranslationResourcesGathered(
            resource: $resourceKey,
            page: $paginator->currentPage(),
            count: $paginator->count(),
            actor: $actor,
        ));

        return $paginator;
    }

    /**
     * Find and normalize one logical resource record.
     */
    public function find(
        string $resourceKey,
        int|string $id,
        TranslationActorData $actor,
    ): TranslationResourceRecordData {
        $resource = $this->resources->get($resourceKey);
        $model = $resource->newModel();
        $this->assertAvailable($resource, $model);
        $record = $this->locator->find($resource, $id);
        $this->authorization->authorize($actor, TranslationResourceAbility::View, $resource, $record);

        return $this->serialize($resource, $record);
    }

    /**
     * Determine whether all persistence tables required by a resource exist.
     */
    private function isAvailable(Model&TranslatableResourceModel $model): bool
    {
        $ownerAvailable = $model->getConnection()
            ->getSchemaBuilder()
            ->hasTable($model->getTable());

        if (! $ownerAvailable || ! $model instanceof TranslatableModel) {
            return $ownerAvailable;
        }

        $translationModel = $model->translations()->getRelated();

        return $translationModel->getConnection()
            ->getSchemaBuilder()
            ->hasTable($translationModel->getTable());
    }

    /**
     * Reject direct reads until every required persistence table exists.
     */
    private function assertAvailable(
        TranslationResourceDefinition $resource,
        Model&TranslatableResourceModel $model,
    ): void {
        if ($this->isAvailable($model)) {
            return;
        }

        $translationTable = $model instanceof TranslatableModel
            ? $model->translations()->getRelated()->getTable()
            : $model->getTable();

        throw TranslationResourceException::unavailable(
            $resource->key,
            $model->getTable(),
            $translationTable,
        );
    }

    /**
     * Count logical resources rather than physical locale rows.
     */
    private function logicalTotal(TranslationResourceDefinition $resource): int
    {
        return $this->locator->query($resource)->count();
    }

    /**
     * Count logical resources containing one exact locale row.
     */
    private function translatedTotal(
        TranslationResourceDefinition $resource,
        Model&TranslatableResourceModel $model,
        string $locale,
    ): int {
        $definition = $model->translationDefinition();
        $query = $this->locator->query($resource);

        if ($model instanceof SelfTranslatableModel
            && $definition instanceof SelfTranslationDefinition) {
            $table = $model->getTable();
            $alias = 'translation_coverage_rows';

            return $query->whereExists(
                static function (QueryBuilder $translatedQuery) use (
                    $alias,
                    $definition,
                    $locale,
                    $table,
                ): void {
                    $translatedQuery
                        ->selectRaw('1')
                        ->from("{$table} as {$alias}")
                        ->whereColumn(
                            "{$alias}.{$definition->groupKey}",
                            "{$table}.{$definition->groupKey}",
                        )
                        ->where("{$alias}.{$definition->localeKey}", $locale);
                },
            )->count();
        }

        if (! $model instanceof TranslatableModel) {
            throw new LogicException('Unsupported translatable resource model.');
        }

        return $query
            ->whereHas(
                'translations',
                static fn (Builder $query): Builder => $query->where(
                    $definition->localeKey,
                    $locale,
                ),
            )
            ->count();
    }

    /**
     * Apply escaped search across explicitly registered columns.
     *
     * @param  Builder<Model>  $builder
     */
    private function applySearch(
        Builder $builder,
        TranslationResourceDefinition $resource,
        Model&TranslatableResourceModel $model,
        ?string $search,
    ): void {
        if ($search === null || trim($search) === '' || $resource->searchableColumns === []) {
            return;
        }

        $term = '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            trim($search),
        ).'%';

        if ($model instanceof SelfTranslatableModel) {
            $this->applySelfSearch($builder, $resource, $model, $term);

            return;
        }

        $builder->where(function (Builder $nested) use ($resource, $term): void {
            $this->applySearchColumns($nested, $resource->searchableColumns, $term);
        });
    }

    /**
     * Search every locale row belonging to a self-translated logical resource.
     *
     * @param  Builder<Model>  $builder
     */
    private function applySelfSearch(
        Builder $builder,
        TranslationResourceDefinition $resource,
        Model&SelfTranslatableModel $model,
        string $term,
    ): void {
        $definition = $model->translationDefinition();
        $table = $model->getTable();
        $alias = 'translation_search_rows';

        $builder->whereExists(
            function (QueryBuilder $query) use (
                $alias,
                $definition,
                $resource,
                $table,
                $term,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from("{$table} as {$alias}")
                    ->whereColumn(
                        "{$alias}.{$definition->groupKey}",
                        "{$table}.{$definition->groupKey}",
                    )
                    ->where(function (QueryBuilder $nested) use (
                        $alias,
                        $resource,
                        $term,
                    ): void {
                        foreach ($resource->searchableColumns as $index => $column) {
                            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                            $wrapped = $nested->getGrammar()->wrap("{$alias}.{$column}");
                            $sql = "{$wrapped} LIKE ? ESCAPE '!'";
                            $this->assertSafeSearchSql($sql);
                            $nested->{$method}($sql, [$term]);
                        }
                    });
            },
        );
    }

    /**
     * Add a grouped escaped search expression for known-safe columns.
     *
     * @param  Builder<Model>  $builder
     * @param  list<string>  $columns
     */
    private function applySearchColumns(Builder $builder, array $columns, string $term): void
    {
        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $wrappedColumn = $builder->getQuery()->getGrammar()->wrap($column);
            $sql = "{$wrappedColumn} LIKE ? ESCAPE '!'";
            $this->assertSafeSearchSql($sql);
            $builder->{$method}($sql, [$term]);
        }
    }

    /**
     * Assert that generated search SQL contains only quoted identifiers and fixed syntax.
     *
     * @phpstan-assert literal-string $sql
     */
    private function assertSafeSearchSql(string $sql): void
    {
        if (preg_match("/^[A-Za-z0-9_.\"`\\[\\]]+ LIKE \\? ESCAPE '!'$/D", $sql) !== 1) {
            throw TranslationResourceException::invalid('A searchable column produced unsafe SQL.');
        }
    }

    /**
     * Limit records to logical resources missing one supported locale row.
     *
     * @param  Builder<Model>  $builder
     */
    private function applyMissingLocale(
        Builder $builder,
        Model&TranslatableResourceModel $model,
        ?string $missingLocale,
    ): void {
        if ($missingLocale === null || trim($missingLocale) === '') {
            return;
        }

        $definition = $model->translationDefinition();
        $locale = $definition->assertLocale(
            $this->locales->assertSupported($missingLocale),
        );

        if ($model instanceof SelfTranslatableModel) {
            $table = $model->getTable();
            $alias = 'translation_missing_rows';

            $builder->whereNotExists(
                static function (QueryBuilder $query) use (
                    $alias,
                    $definition,
                    $locale,
                    $table,
                ): void {
                    if (! $definition instanceof SelfTranslationDefinition) {
                        throw new LogicException('Expected a self-translation definition.');
                    }

                    $query
                        ->selectRaw('1')
                        ->from("{$table} as {$alias}")
                        ->whereColumn(
                            "{$alias}.{$definition->groupKey}",
                            "{$table}.{$definition->groupKey}",
                        )
                        ->where("{$alias}.{$definition->localeKey}", $locale);
                },
            );

            return;
        }

        $builder->whereDoesntHave(
            'translations',
            static fn (Builder $translationQuery): Builder => $translationQuery->where(
                $definition->localeKey,
                $locale,
            ),
        );
    }

    /**
     * Normalize one logical translatable resource for central management consumers.
     */
    private function serialize(
        TranslationResourceDefinition $resource,
        Model $record,
    ): TranslationResourceRecordData {
        if (! $record instanceof TranslatableResourceModel) {
            throw new LogicException('Registered translation records must be translatable resources.');
        }

        $definition = $record->translationDefinition();
        $translations = [];

        foreach ($record->getAllTranslations() as $translation) {
            $locale = $translation->getAttribute($definition->localeKey);

            if (! is_string($locale)) {
                continue;
            }

            try {
                $normalizedLocale = $definition->assertLocale($locale);
            } catch (InvalidLocaleException) {
                continue;
            }

            if ($locale !== $normalizedLocale) {
                continue;
            }

            $translations[$normalizedLocale] = collect($definition->fields)
                ->mapWithKeys(fn (string $field): array => [
                    $field => $this->normalizeValue($translation->getAttribute($field)),
                ])
                ->all();
        }

        ksort($translations);
        $configuredLocales = array_values(array_intersect(
            $definition->supportedLocales(),
            $this->locales->supported(),
        ));
        $translatedLocales = array_keys($translations);
        $missingLocales = array_values(array_diff($configuredLocales, $translatedLocales));
        $attributes = collect($resource->displayColumns)
            ->mapWithKeys(fn (string $column): array => [
                $column => $this->normalizeValue($record->getAttribute($column)),
            ])
            ->all();
        $labelParts = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $attributes,
            ),
            static fn (string $value): bool => trim($value) !== '',
        ));
        $id = $record->translationResourceKey();

        return new TranslationResourceRecordData(
            resource: $resource->key,
            id: $id,
            label: $labelParts !== []
                ? implode(' · ', $labelParts)
                : class_basename($record).' #'.$id,
            attributes: $attributes,
            translations: $translations,
            translatedLocales: $translatedLocales,
            missingLocales: $missingLocales,
            version: $this->versioner->version($record),
        );
    }

    /**
     * Normalize enum values for JSON-safe gathered records.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value;
    }
}
