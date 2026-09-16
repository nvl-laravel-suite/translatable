<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Relations\TranslationResourceBuilder;
use Nvl\Translatable\TranslationDefinition;
use Nvl\Translatable\TranslationResourceDefinition;
use WeakMap;

/**
 * Locates and preloads logical translatable resources across both storage strategies.
 */
final readonly class TranslationResourceLocator
{
    /** @var WeakMap<Model, array{context: TenantContextSnapshot, definition: TranslationDefinition, partitionKey: string}> */
    private WeakMap $resolvedIdentities;

    /** Resolve central queries and preloads through canonical ownership boundaries. */
    public function __construct(
        private TranslationOwnership $ownership,
        private RelatedTranslationStore $relatedTranslations,
        private TenantContext $context,
        private TenantResourceRegistry $tenantResources,
    ) {
        $this->resolvedIdentities = new WeakMap;
    }

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
        foreach ($records as $model) {
            unset($this->resolvedIdentities[$model]);
        }
        $record = $records->first();

        if (! $record instanceof TranslatableResourceModel) {
            return;
        }

        if (! $record instanceof TranslatableModel && ! $record instanceof SelfTranslatableModel) {
            return;
        }

        $definition = $record->translationDefinition();
        $partitionColumns = $this->ownership->partitionColumns($definition);
        $logicalColumn = $definition instanceof RelatedTranslationDefinition
            ? $definition->ownerKey
            : $definition->groupKey;
        $identities = $this->canonicalIdentities(
            $records,
            $record,
            $definition,
            $partitionColumns,
            $logicalColumn,
        );
        $rowLogicalColumn = $definition instanceof RelatedTranslationDefinition
            ? $definition->foreignKey($record->getTable())
            : $logicalColumn;
        if ($definition instanceof RelatedTranslationDefinition) {
            $translation = new $definition->translationModel;
            if ($translation->getConnectionName() === null) {
                $translation->setConnection($record->getConnectionName());
            }
            $query = $this->relatedTranslations->scopeQuery(
                $translation->newQuery(),
                $identities[0]['canonical'],
                $definition,
            );
        } else {
            $query = $this->ownership->query($record->newQuery(), $definition);
        }
        $query->where(function (Builder $nested) use ($identities, $partitionColumns, $rowLogicalColumn): void {
            foreach ($identities as $identity) {
                $nested->orWhere(function (Builder $branch) use ($identity, $partitionColumns, $rowLogicalColumn): void {
                    foreach ($partitionColumns as $column) {
                        $branch->where($column, $identity['values'][$column]);
                    }
                    $branch->where($rowLogicalColumn, $identity['values']['logical']);
                });
            }
        })->orderBy($definition->localeKey);
        $rowsByPartition = [];
        foreach ($query->get() as $row) {
            foreach ($identities as $identity) {
                if ($this->matchesIdentity($row, $rowLogicalColumn, $identity, $partitionColumns)) {
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
            $this->resolvedIdentities[$identity['model']] = [
                'context' => $this->context->snapshot(),
                'definition' => $definition,
                'partitionKey' => $identity['partitionKey'],
            ];
        }
    }

    /** Consume an admitted partition key only in the unchanged tenant context. */
    public function consumeResolvedPartitionKey(Model $model, TranslationDefinition $definition): ?string
    {
        $resolved = $this->resolvedIdentities[$model] ?? null;
        unset($this->resolvedIdentities[$model]);

        return $resolved !== null
            && $resolved['definition'] == $definition
            && $resolved['context'] === $this->context->snapshot()
            ? $resolved['partitionKey']
            : null;
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
        $definition = $canonical->translationDefinition();
        $this->assertCanonicalStorage($resource, $query, $canonical);
        $query = $this->ownership->query($query, $definition);

        if ($resource->queryScope !== null) {
            $query = ($resource->queryScope)($query);
            $this->assertCanonicalStorage($resource, $query, $canonical);
        }
        $query = $query->applyScopes();
        $query->getQuery()->applyBeforeQueryCallbacks();
        $this->assertCanonicalStorage($resource, $query, $canonical);
        $query->withoutGlobalScopes();
        $guarded = TranslationResourceBuilder::guarded(
            $query,
            $resource->key,
            $canonical,
            $definition,
            $this->ownership,
        );

        return $this->ownership->query($guarded, $definition);
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
     * @param  array{model: Model, canonical: Model, partitionKey: string, values: array<string, mixed>}  $identity
     * @param  list<string>  $partitionColumns
     */
    private function matchesIdentity(
        Model $row,
        string $groupColumn,
        array $identity,
        array $partitionColumns,
    ): bool {
        if ($row->getRawOriginal($groupColumn) !== $identity['values']['logical']) {
            return false;
        }

        foreach ($partitionColumns as $column) {
            if ($row->getRawOriginal($column) !== $identity['values'][$column]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reload and admit every supplied owner through one canonical batch query.
     *
     * @param  Collection<int, covariant Model>  $records
     * @param  list<string>  $partitionColumns
     * @return list<array{model: Model, canonical: Model, partitionKey: string, values: array<string, mixed>}>
     */
    private function canonicalIdentities(
        Collection $records,
        Model&TranslatableResourceModel $record,
        TranslationDefinition $definition,
        array $partitionColumns,
        string $logicalColumn,
    ): array {
        $keyName = $record->getKeyName();
        $suppliedByKey = [];
        $keys = [];
        foreach ($records as $model) {
            if (! $model instanceof TranslatableResourceModel
                || ! $this->compatible($record, $model)
                || $model->translationDefinition() != $definition) {
                throw TranslationResourceException::invalid(
                    'Translation preload batches require one compatible model, connection, and ownership declaration.',
                );
            }
            $key = $model->getRawOriginal($keyName);
            if (! $model->exists || (! is_int($key) && ! is_string($key))) {
                throw TranslationResourceException::invalid(
                    'Translation preload batches require persisted logical identities.',
                );
            }
            $encodedKey = $this->encodedIdentity($key);
            if (isset($suppliedByKey[$encodedKey])) {
                throw new TenantBoundaryViolation('Translation preload batches cannot contain duplicate owners.');
            }
            $suppliedByKey[$encodedKey] = $model;
            $keys[] = $key;
        }
        $canonicalQuery = $record->newQuery();
        if ($record instanceof SelfTranslatableModel) {
            $canonicalQuery->withoutGlobalScope(SoftDeletingScope::class);
        }
        $canonicalRows = $this->ownership->query($canonicalQuery, $definition)
            ->whereKey($keys)
            ->get();
        $canonicalByKey = [];
        foreach ($canonicalRows as $canonical) {
            $key = $canonical->getRawOriginal($keyName);
            if (! is_int($key) && ! is_string($key)) {
                throw new TenantBoundaryViolation('Canonical translation owners require persisted keys.');
            }
            $encodedKey = $this->encodedIdentity($key);
            if (isset($canonicalByKey[$encodedKey])) {
                throw new TenantBoundaryViolation('Canonical translation owner identities must be unique.');
            }
            $canonicalByKey[$encodedKey] = $canonical;
        }
        if (count($canonicalByKey) !== count($suppliedByKey)) {
            throw new TenantBoundaryViolation('A canonical translation owner is unavailable in the active context.');
        }
        $identities = [];
        $logicalIdentities = [];
        $identityColumns = $this->identityColumns(
            $record,
            $definition,
            $partitionColumns,
            $logicalColumn,
        );
        foreach ($suppliedByKey as $encodedKey => $model) {
            $canonical = $canonicalByKey[$encodedKey] ?? null;
            if (! $canonical instanceof Model) {
                throw new TenantBoundaryViolation('A canonical translation owner is unavailable in the active context.');
            }
            foreach ($identityColumns as $column) {
                $value = $canonical->getRawOriginal($column);
                if ($model->getRawOriginal($column) !== $value || $model->getAttribute($column) !== $value) {
                    throw new TenantBoundaryViolation('The supplied translation owner identity is stale or forged.');
                }
            }
            $logical = $canonical->getRawOriginal($logicalColumn);
            if (! is_int($logical) && ! is_string($logical)) {
                throw new TenantBoundaryViolation('Canonical translation owners require logical identities.');
            }
            $values = ['logical' => $logical];
            foreach ($partitionColumns as $column) {
                $values[$column] = $canonical->getRawOriginal($column);
            }
            $logicalKey = $this->encodedIdentity($values);
            if (isset($logicalIdentities[$logicalKey])) {
                throw new TenantBoundaryViolation('Canonical translation logical identities must be unique.');
            }
            $logicalIdentities[$logicalKey] = true;
            $partitionKey = $this->canonicalPartitionKey($canonical, $definition, $partitionColumns, $logical);
            $identities[] = [
                'model' => $model,
                'canonical' => $canonical,
                'partitionKey' => $partitionKey,
                'values' => $values,
            ];
        }

        return $identities;
    }

    /**
     * Return only canonical storage and ownership identity columns for one batch.
     *
     * @param  list<string>  $partitionColumns
     * @return list<string>
     */
    private function identityColumns(
        Model $model,
        TranslationDefinition $definition,
        array $partitionColumns,
        string $logicalColumn,
    ): array {
        $columns = [$model->getKeyName(), ...$partitionColumns, $logicalColumn];
        if ($definition->ownershipResource === null) {
            return array_values(array_unique($columns));
        }
        $resource = $this->tenantResources->get($definition->ownershipResource);
        if ($resource->kind !== TenantResourceKind::Inherited) {
            return array_values(array_unique($columns));
        }
        $canonical = new $resource->model;
        $relation = Relation::noConstraints(fn () => $canonical->{$resource->parentRelation}());
        if (! $relation instanceof BelongsTo) {
            throw new TenantConfigurationInvalid('Translation inheritance requires a belongs-to parent.');
        }
        $columns[] = $relation->getForeignKeyName();
        if ($relation instanceof MorphTo) {
            $columns[] = $relation->getMorphType();
        }

        return array_values(array_unique($columns));
    }

    /**
     * Encode one admitted canonical partition without reloading it.
     *
     * @param  list<string>  $partitionColumns
     */
    private function canonicalPartitionKey(
        Model $canonical,
        TranslationDefinition $definition,
        array $partitionColumns,
        int|string $logical,
    ): string {
        $attributes = [];
        if ($partitionColumns !== []) {
            $tenant = $canonical->getRawOriginal('tenant_id');
            if (! is_string($tenant) && $tenant !== null) {
                throw new TenantBoundaryViolation('Persisted translation ownership is invalid.');
            }
            $attributes['tenant_id'] = $tenant;
            if ($partitionColumns === ['ownership_key']) {
                $ownershipKey = $canonical->getRawOriginal('ownership_key');
                if (! is_string($ownershipKey)
                    || $ownershipKey !== ($tenant === null ? 'platform' : 'tenant:'.$tenant)) {
                    throw new TenantBoundaryViolation('Persisted translation partition is invalid.');
                }
                $attributes['ownership_key'] = $ownershipKey;
            }
        }

        return hash('sha256', json_encode([
            $canonical->getConnection()->getName(),
            $canonical->getTable(),
            $definition->ownershipResource,
            $attributes,
            $logical,
        ], JSON_THROW_ON_ERROR));
    }

    /** Encode scalar and composite identities without string coercion collisions. */
    private function encodedIdentity(mixed $identity): string
    {
        return json_encode($identity, JSON_THROW_ON_ERROR);
    }

    /**
     * Reject callback replacement or mutation away from declared canonical SQL storage.
     *
     * @param  Builder<Model>  $query
     */
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
