<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Locates and preloads logical translatable resources across both storage strategies.
 */
final readonly class TranslationResourceLocator
{
    /** Resolve central queries through the canonical ownership boundary. */
    public function __construct(private TranslationOwnership $ownership) {}

    /**
     * Build a query returning one deterministic representative per logical resource.
     *
     * @return Builder<Model>
     */
    public function query(TranslationResourceDefinition $resource): Builder
    {
        $model = $resource->newModel();

        if ($model instanceof TranslatableModel) {
            return $this->applyQueryScope($resource, $model->newQuery());
        }

        if (! $model instanceof SelfTranslatableModel) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$resource->key}] uses an unsupported model contract.",
            );
        }

        $definition = $model->translationDefinition();
        $table = $model->getTable();
        $alias = 'translation_representatives';
        $query = $this->applyQueryScope($resource, $model->newQuery());
        $partitionColumns = $this->ownership->partitionColumns($definition);
        $visibleRows = (clone $query)
            ->select(array_map(
                static fn (string $column): string => "{$table}.{$column}",
                [...$partitionColumns, $definition->groupKey, $definition->localeKey],
            ))
            ->reorder()
            ->toBase()
            ->cloneWithout(['limit', 'offset']);

        return $query->whereNotExists(
            static function (QueryBuilder $query) use (
                $alias,
                $definition,
                $partitionColumns,
                $table,
                $visibleRows,
            ): void {
                $query->selectRaw('1')->fromSub($visibleRows, $alias);

                foreach ($partitionColumns as $column) {
                    $query->whereColumn("{$alias}.{$column}", "{$table}.{$column}");
                }

                $query
                    ->whereColumn(
                        "{$alias}.{$definition->groupKey}",
                        "{$table}.{$definition->groupKey}",
                    )
                    ->whereColumn(
                        "{$alias}.{$definition->localeKey}",
                        '<',
                        "{$table}.{$definition->localeKey}",
                    );
            },
        );

    }

    /**
     * Find one logical resource and preload all of its locale rows.
     */
    public function find(
        TranslationResourceDefinition $resource,
        int|string $id,
    ): Model&TranslatableResourceModel {
        $model = $resource->newModel();
        $record = $model instanceof SelfTranslatableModel
            ? $this->findSelfRecord($resource, $model, $id)
            : $this->query($resource)->findOrFail($id);

        if (! $record instanceof TranslatableResourceModel) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$resource->key}] returned an unsupported model.",
            );
        }

        $records = new Collection([$record]);
        $this->loadTranslations($records);

        return $record;
    }

    /**
     * Lock one logical resource and preload every locale row inside the caller's transaction.
     */
    public function lock(
        TranslationResourceDefinition $resource,
        int|string $id,
    ): Model&TranslatableResourceModel {
        $model = $resource->newModel();

        if ($model instanceof TranslatableModel) {
            $record = $this->query($resource)
                ->lockForUpdate()
                ->findOrFail($id);

            if (! $record instanceof TranslatableResourceModel) {
                throw TranslationResourceException::invalid(
                    "Translation resource [{$resource->key}] returned an unsupported model.",
                );
            }

            $record->load('translations');

            return $record;
        }

        if (! $model instanceof SelfTranslatableModel) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$resource->key}] uses an unsupported model contract.",
            );
        }

        $definition = $model->translationDefinition();
        $query = $this->applyQueryScope($resource, $model->newQuery())
            ->lockForUpdate();
        $query->getQuery()
            ->where($definition->groupKey, $id)
            ->orderBy($definition->localeKey);
        $rows = $query->get();
        $record = $rows->first();

        if (! $record instanceof Model || ! $record instanceof SelfTranslatableModel) {
            $missingQuery = $this->applyQueryScope($resource, $model->newQuery());
            $missingQuery->getQuery()->where($definition->groupKey, $id);
            $missingQuery->firstOrFail();

            throw TranslationResourceException::invalid(
                "Translation resource [{$resource->key}] could not lock its logical group.",
            );
        }

        $record->setRelation('translations', $rows);

        return $record;
    }

    /**
     * Preload translation rows for a collection without per-resource queries.
     *
     * @param  Collection<int, covariant Model>  $records
     */
    public function loadTranslations(Collection $records): void
    {
        $record = $records->first();

        if (! $record instanceof TranslatableResourceModel) {
            return;
        }

        if ($record instanceof TranslatableModel) {
            $definition = $record->translationDefinition();

            foreach ($records as $model) {
                if (! $model instanceof TranslatableModel
                    || ! $this->compatible($record, $model)
                    || $model->translationDefinition() != $definition) {
                    throw TranslationResourceException::invalid(
                        'Translation preload batches require one compatible model, connection, and ownership declaration.',
                    );
                }

                $this->ownership->partitionKey($model, $definition);
            }

            $records->load('translations');

            return;
        }

        if (! $record instanceof SelfTranslatableModel) {
            return;
        }

        $definition = $record->translationDefinition();
        $partitionColumns = $this->ownership->partitionColumns($definition);
        $identities = [];

        foreach ($records as $model) {
            if (! $model instanceof SelfTranslatableModel
                || ! $this->compatible($record, $model)
                || $model->translationDefinition() != $definition) {
                throw TranslationResourceException::invalid(
                    'Translation preload batches require one compatible model, connection, and ownership declaration.',
                );
            }

            $groupValue = $model->getRawOriginal($definition->groupKey);
            if (! is_int($groupValue) && ! is_string($groupValue)) {
                throw TranslationResourceException::invalid(
                    'Translation preload batches require persisted logical identities.',
                );
            }
            $partitionKey = $this->ownership->partitionKey($model, $definition);
            $identity = ['group' => $groupValue, 'partitionKey' => $partitionKey, 'model' => $model];
            foreach ($partitionColumns as $column) {
                $identity[$column] = $model->getRawOriginal($column);
            }
            $identities[] = $identity;
        }

        $query = $this->ownership->query($record->newQuery(), $definition);
        $query->where(function (Builder $nested) use ($definition, $identities, $partitionColumns): void {
            foreach ($identities as $identity) {
                $nested->orWhere(function (Builder $branch) use ($definition, $identity, $partitionColumns): void {
                    foreach ($partitionColumns as $column) {
                        $branch->where($column, $identity[$column]);
                    }
                    $branch->where($definition->groupKey, $identity['group']);
                });
            }
        })->orderBy($definition->localeKey);
        $rowsByPartition = [];
        foreach ($query->get() as $row) {
            foreach ($identities as $identity) {
                if ($this->matchesIdentity($row, $definition->groupKey, $identity, $partitionColumns)) {
                    $rowsByPartition[$identity['partitionKey']] ??= new Collection;
                    $rowsByPartition[$identity['partitionKey']]->push($row);
                    break;
                }
            }
        }

        foreach ($identities as $identity) {
            $identity['model']->setRelation(
                'translations',
                $rowsByPartition[$identity['partitionKey']] ?? new Collection,
            );
        }
    }

    /**
     * Build a visible, ownership-scoped row query without representative reduction.
     *
     * @return Builder<Model>
     */
    public function rows(TranslationResourceDefinition $resource): Builder
    {
        $model = $resource->newModel();

        return $this->applyQueryScope($resource, $model->newQuery());
    }

    /**
     * Find one representative row by its logical grouped-resource key.
     */
    private function findSelfRecord(
        TranslationResourceDefinition $resource,
        Model&SelfTranslatableModel $model,
        int|string $id,
    ): Model {
        $query = $this->query($resource);
        $query->getQuery()->where($model->translationDefinition()->groupKey, $id);

        return $query->firstOrFail();
    }

    /**
     * Apply the resource's visibility boundary to every central query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyQueryScope(
        TranslationResourceDefinition $resource,
        Builder $query,
    ): Builder {
        $canonical = $resource->newModel();
        $this->assertCanonicalStorage($resource, $query, $canonical);
        $query = $this->ownership->query($query, $canonical->translationDefinition());

        if ($resource->queryScope === null) {
            return $query;
        }

        $scoped = ($resource->queryScope)($query);
        $this->assertCanonicalStorage($resource, $scoped, $canonical);

        return $this->ownership->query($scoped, $canonical->translationDefinition());
    }

    /** Determine whether two batch models use the same canonical storage. */
    private function compatible(Model $expected, Model $candidate): bool
    {
        return $candidate::class === $expected::class
            && $candidate->getTable() === $expected->getTable()
            && $candidate->getConnection() === $expected->getConnection();
    }

    /**
     * Match one fetched row to an admitted composite logical identity.
     *
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $partitionColumns
     */
    private function matchesIdentity(
        Model $row,
        string $groupColumn,
        array $identity,
        array $partitionColumns,
    ): bool {
        if ($row->getRawOriginal($groupColumn) !== $identity['group']) {
            return false;
        }

        foreach ($partitionColumns as $column) {
            if ($row->getRawOriginal($column) !== $identity[$column]) {
                return false;
            }
        }

        return true;
    }

    /** Reject callback replacement or mutation away from declared canonical SQL storage. */
    private function assertCanonicalStorage(
        TranslationResourceDefinition $resource,
        Builder $query,
        Model $canonical,
    ): void {
        $model = $query->getModel();
        $base = $query->getQuery();

        if ($model::class !== $canonical::class
            || $model->getTable() !== $canonical->getTable()
            || $model->getConnection() !== $canonical->getConnection()
            || $base->getConnection() !== $canonical->getConnection()
            || $base->from !== $canonical->getTable()
            || $base->unions !== null) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$resource->key}] query scope must preserve its registered model, table, and connection.",
            );
        }
    }
}
