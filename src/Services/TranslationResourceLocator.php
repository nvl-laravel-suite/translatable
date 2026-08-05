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

        $query = $model->newQuery()->whereNotExists(
            static function (QueryBuilder $query) use (
                $alias,
                $definition,
                $table,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from("{$table} as {$alias}")
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

        return $this->applyQueryScope($resource, $query);
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
            $records->load('translations');

            return;
        }

        if (! $record instanceof SelfTranslatableModel) {
            return;
        }

        $definition = $record->translationDefinition();
        $groupValues = $records
            ->map(
                static fn (Model $model): mixed => $model->getAttribute($definition->groupKey),
            )
            ->filter(
                static fn (mixed $value): bool => is_int($value) || is_string($value),
            )
            ->values()
            ->all();
        $query = $record->newQuery();
        $query->getQuery()
            ->whereIn($definition->groupKey, $groupValues)
            ->orderBy($definition->localeKey);
        $rows = $query->get()->groupBy($definition->groupKey);

        foreach ($records as $model) {
            if (! $model instanceof SelfTranslatableModel) {
                continue;
            }

            $groupValue = $model->getAttribute($definition->groupKey);

            if (! is_int($groupValue) && ! is_string($groupValue)) {
                $model->setRelation('translations', new Collection);

                continue;
            }

            $groupRows = $rows->get($groupValue, new Collection);
            $model->setRelation('translations', $groupRows);
        }
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
        if ($resource->queryScope === null) {
            return $query;
        }

        $scoped = ($resource->queryScope)($query);
        $originalModel = $query->getModel();
        $scopedModel = $scoped->getModel();

        if ($scopedModel::class !== $originalModel::class
            || $scopedModel->getConnection()->getName()
                !== $originalModel->getConnection()->getName()
            || $scopedModel->getTable() !== $originalModel->getTable()) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$resource->key}] query scope must preserve its registered model, table, and connection.",
            );
        }

        return $scoped;
    }
}
