<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipIdentityFields;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\TranslationDefinition;

/**
 * Connects translation operations to exact model-declared primary ownership.
 *
 * @internal
 */
final readonly class TranslationOwnership
{
    /** Return the native ownership failure for an invalid translation boundary. */
    public function violation(string $message): OwnershipViolation
    {
        return new OwnershipViolation($message);
    }

    /** Use the Core-owned inert contracts until the optional Ownership runtime is installed. */
    public function __construct(
        private OwnershipBoundary $boundary,
        private OwnershipContext $context,
        private OwnershipResourceRegistry $resources,
        private OwnershipReadiness $readiness,
        private PersistedOwnershipStorage $storage,
        private OwnershipWriter $writer,
    ) {}

    /** Coordinate every owned new locale row through its exact registered primary assignment.
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  Closure(array<string, int|string|null>): T  $persist
     * @return T
     */
    public function create(string $class, Closure $persist): Model
    {
        if (! $this->disabled()) {
            foreach ($this->resources->all() as $resource) {
                if ($resource->model === $class && $resource->ownership->strategy !== 'unscoped') {
                    return $this->writer->create($class, $persist);
                }
            }
        }

        return $persist([]);
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, TranslationDefinition $definition): Builder
    {
        if ($definition->ownershipResource === null) {
            $this->assertUnowned($query->getModel());

            return $query;
        }

        return $this->boundary->query($query, $definition->ownershipResource);
    }

    /** Admit persisted ownership even for previously loaded translation state. */
    public function assertOwner(Model $owner, TranslationDefinition $definition): void
    {
        if ($definition->ownershipResource === null) {
            $this->assertUnowned($owner);
        } else {
            $this->boundary->assertRecord($owner, $definition->ownershipResource);
        }
    }

    /** Reload immutable structural facts under their actual source transaction lock. */
    public function lockOwner(Model $owner, TranslationDefinition $definition): Model
    {
        $this->assertOwner($owner, $definition);
        if ($owner->getConnection()->transactionLevel() < 1) {
            throw new OwnershipViolation('Translation writes require their actual source transaction.');
        }
        $identity = [$owner->getKeyName(), 'owner_scope_key', 'owner_reference'];
        if ($definition->ownershipResource !== null) {
            $resource = $this->resources->get($definition->ownershipResource);
            $ownership = $resource->ownership;
            if ($ownership->foreignKey !== null) {
                $identity[] = $ownership->foreignKey;
            }
            if ($ownership->strategy === 'inherited') {
                $canonical = new $resource->model;
                $name = $ownership->relation ?? throw new OwnershipConfigurationInvalid;
                $relation = Relation::noConstraints(fn () => $canonical->{$name}());
                if (! $relation instanceof BelongsTo) {
                    throw new OwnershipConfigurationInvalid('Translation inheritance requires a canonical belongs-to relation.');
                }
                $identity[] = $relation->getForeignKeyName();
                if ($relation instanceof MorphTo) {
                    $identity[] = $relation->getMorphType();
                }
            }
        }
        if ($definition instanceof SelfTranslationDefinition) {
            $identity = [...$identity, $definition->groupKey, $definition->localeKey];
        } elseif ($definition instanceof RelatedTranslationDefinition) {
            $identity[] = $definition->ownerKey;
        }
        if ($owner->isDirty($identity)) {
            throw new OwnershipViolation('Translation ownership and structural identity are immutable.');
        }
        $key = $owner->getRawOriginal($owner->getKeyName());
        if (! $owner->exists || (! is_string($key) && ! is_int($key))) {
            throw new OwnershipViolation('Translation locking requires a persisted native identity.');
        }
        $persisted = $this->query($owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class), $definition)->whereKey($key)->lockForUpdate()->first();
        if (! $persisted instanceof Model) {
            throw new OwnershipViolation('The canonical translation owner is unavailable.');
        }
        foreach ($identity as $field) {
            if (! $this->sameIdentity($field, $persisted->getRawOriginal($field), $owner->getRawOriginal($field))) {
                throw new OwnershipViolation('Persisted translation structure changed.');
            }
        }
        $this->assertOwner($persisted, $definition);

        return $persisted;
    }

    /** Reload all eager owners in one admitted canonical query without caching authority.
     * @param  array<int,Model>  $owners
     * @return list<array<string,mixed>>|null
     */
    public function eagerOwnerIdentities(array $owners, RelatedTranslationDefinition $definition): ?array
    {
        if ($definition->ownershipResource === null) {
            if ($owners === []) {
                return [];
            }
            $this->assertUnowned($owners[0]);
            $canonical = $owners[0];
            $identities = [];
            foreach ($owners as $owner) {
                $key = $owner->getRawOriginal($definition->ownerKey);
                if ($owner::class !== $canonical::class || $owner->getTable() !== $canonical->getTable()
                    || $owner->getConnection() !== $canonical->getConnection() || ! $owner->exists
                    || (! is_int($key) && ! is_string($key))
                    || $owner->isDirty([$owner->getKeyName(), $definition->ownerKey])) {
                    throw new OwnershipViolation('Eager translations require unchanged canonical owner identities.');
                }
                $identities[] = [$definition->foreignKey($owner->getTable()) => $key];
            }

            return $identities;
        }
        if ($this->disabled() || $this->resources->get($definition->ownershipResource)->ownership->strategy === 'unscoped') {
            return null;
        }
        $resource = $this->resources->get($definition->ownershipResource);
        $canonical = new $resource->model;
        $fields = [...((new OwnershipIdentityFields)->forResource($resource)), $definition->ownerKey];
        $keys = [];
        foreach ($owners as $owner) {
            $key = $owner->getRawOriginal($canonical->getKeyName());
            if ($owner::class !== $canonical::class || ! $owner->exists || $owner->getTable() !== $canonical->getTable() || $owner->getConnection() !== $canonical->getConnection() || (! is_string($key) && ! is_int($key)) || $owner->isDirty($fields)) {
                throw new OwnershipViolation('Eager translations require unchanged canonical owner identities.');
            }
            $keys[] = $key;
        }
        $query = $canonical->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->useWritePdo()->whereKey($keys);
        $fresh = $this->boundary->query($query, $resource->key)->get()->keyBy($canonical->getKeyName());
        $identities = [];
        foreach (array_values($owners) as $index => $owner) {
            $row = $fresh->get($keys[$index]);
            if (! $row instanceof Model || $row->getRawOriginal($definition->ownerKey) !== $owner->getRawOriginal($definition->ownerKey) || $row->getRawOriginal('owner_scope_key') !== $owner->getRawOriginal('owner_scope_key')) {
                throw new OwnershipViolation('An eager translation owner is stale or outside the active boundary.');
            }
            foreach ($fields as $field) {
                if (! $this->sameIdentity($field, $row->getRawOriginal($field), $owner->getRawOriginal($field))) {
                    throw new OwnershipViolation('Eager translation ownership facts are stale.');
                }
            }
            $identities[] = [$definition->foreignKey($canonical->getTable()) => $row->getRawOriginal($definition->ownerKey), 'owner_scope_key' => $row->getRawOriginal('owner_scope_key')];
        }

        return $identities;
    }

    /** @return array<string, int|string|null> */
    public function childAttributes(Model $owner, TranslationDefinition $definition): array
    {
        $this->assertOwner($owner, $definition);
        if ($this->partitionColumns($definition) === []) {
            return [];
        }
        $scope = $owner->getRawOriginal('owner_scope_key');
        if (! is_string($scope) || $scope === '') {
            throw new OwnershipViolation('Translation partitions come from the canonical persisted owner.');
        }
        if ($definition instanceof SelfTranslationDefinition) {
            return $this->boundary->attributes($definition->ownershipResource ?? throw new OwnershipConfigurationInvalid);
        }

        return ['owner_scope_key' => $scope];
    }

    /** Describe live structural columns; callers must separately admit the actual model.
     * @return list<string>
     */
    public function partitionColumns(TranslationDefinition $definition): array
    {
        $columns = $this->declaredPartitionColumns($definition);
        if ($columns !== []) {
            $this->readiness->assertActive($definition->ownershipResource ?? throw new OwnershipConfigurationInvalid);
        }

        return $columns;
    }

    /** Read immutable partition metadata before a separately admitted canonical query.
     * @return list<string>
     */
    public function declaredPartitionColumns(TranslationDefinition $definition): array
    {
        if ($definition->ownershipResource === null || $this->resources->get($definition->ownershipResource)->ownership->strategy === 'unscoped') {
            return [];
        }

        return ['owner_scope_key'];
    }

    /** Include admitted generations in translation cache and logical-group identities. */
    public function partitionKey(Model $owner, TranslationDefinition $definition): string
    {
        $attributes = $this->childAttributes($owner, $definition);

        return $this->partitionKeyFromAdmitted($owner, $definition, $attributes);
    }

    /** Derive a logical identity from owners already admitted by one canonical batch query.
     * @param  array<string, int|string|null>  $attributes
     */
    public function partitionKeyFromAdmitted(Model $owner, TranslationDefinition $definition, array $attributes): string
    {
        $column = match (true) {
            $definition instanceof SelfTranslationDefinition => $definition->groupKey,
            $definition instanceof RelatedTranslationDefinition => $definition->ownerKey,
            default => $owner->getKeyName(),
        };
        $snapshot = $this->context->snapshot();

        return hash('sha256', json_encode([
            $definition->ownershipResource, $owner->getConnection()->getName(), $owner->getTable(),
            $attributes, $owner->getRawOriginal($column),
            $snapshot->owner?->toArray(), $snapshot->generations,
        ], JSON_THROW_ON_ERROR));
    }

    /** Resolve shared self-row assignment once for a canonical page.
     * @return array<string, int|string|null>
     */
    public function batchSelfAttributes(SelfTranslationDefinition $definition): array
    {
        if ($definition->ownershipResource === null || $this->declaredPartitionColumns($definition) === []) {
            return [];
        }
        $snapshot = $this->context->snapshot();
        $resource = $this->resources->get($definition->ownershipResource);
        $attributes = ['owner_scope_key' => $snapshot->owner?->scopeKey() ?? 'central'];
        if ($resource->ownership->strategy === 'column') {
            $attributes[$resource->ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $snapshot->owner?->key;
        } elseif ($resource->ownership->strategy === 'captured') {
            $attributes['owner_reference'] = $snapshot->owner === null ? null : json_encode($snapshot->owner->toArray(), JSON_THROW_ON_ERROR);
        }

        return $attributes;
    }

    /** Preserve unowned models without interpreting claimed physical storage as global. */
    private function assertUnowned(Model $model): void
    {
        $this->assertUnownedModels([$model]);
    }

    /** Admit all actual unowned tables together without retaining an authorization or schema cache.
     *
     * @param  list<Model>  $models
     */
    public function assertUnownedModels(array $models): void
    {
        /** @var array<int, array{connection: Connection, tables: list<string>}> $groups */
        $groups = [];
        foreach ($models as $model) {
            if ($model instanceof Ownable && $model::ownershipDefinition()->strategy !== 'unscoped') {
                throw new OwnershipConfigurationInvalid('Declare the translation resource for an owned model.');
            }
            $this->resources->assertUnclaimed($model);
            $connection = $model->getConnection();
            $key = spl_object_id($connection);
            $groups[$key] ??= ['connection' => $connection, 'tables' => []];
            $groups[$key]['tables'][] = $model->getTable();
        }
        foreach ($groups as $group) {
            $this->storage->assertUnadoptedTables($group['connection'], $group['tables']);
        }
    }

    /** Select one authority without retaining any actor or owner model. */
    private function disabled(): bool
    {
        return $this->context->snapshot()->mode === OwnershipContextMode::Disabled;
    }

    /** Compare native PDO scalar representations without collapsing distinct textual keys. */
    private function sameIdentity(string $field, mixed $persisted, mixed $original): bool
    {
        if ($persisted === $original) {
            return true;
        }
        if ($field === 'owner_reference' && is_string($persisted) && is_string($original)) {
            $left = json_decode($persisted, true, 16, JSON_THROW_ON_ERROR);
            $right = json_decode($original, true, 16, JSON_THROW_ON_ERROR);

            return is_array($left) && is_array($right) && OwnerReference::fromArray($left) == OwnerReference::fromArray($right);
        }

        return (is_int($persisted) || is_string($persisted)) && (is_int($original) || is_string($original)) && (string) $persisted === (string) $original;
    }
}
