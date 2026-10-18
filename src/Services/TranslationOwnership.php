<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Tenancy\LegacyTranslationOwnership;
use Nvl\Translatable\TranslationDefinition;

/** Connects translation operations to exact model-declared primary ownership. @internal */
final readonly class TranslationOwnership
{
    /** Return the selected authority's existing presentation contract. */
    public function violation(string $message): BusinessException
    {
        return $this->disabled() ? $this->legacy->violation($message) : new OwnershipViolation($message);
    }

    /** Keep the existing V1 authority isolated in its explicit compatibility adapter. */
    public function __construct(private OwnershipBoundary $boundary, private OwnershipContext $context, private OwnershipResourceRegistry $resources, private OwnershipReadiness $readiness, private LegacyTranslationOwnership $legacy, private PersistedOwnershipStorage $storage, private OwnershipWriter $writer) {}

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
        if ($this->disabled()) {
            return $this->legacy->query($query, $definition);
        }
        if ($definition->ownershipResource === null) {
            $this->assertUnowned($query->getModel());

            return $query;
        }

        return $this->boundary->query($query, $definition->ownershipResource);
    }

    /** Admit persisted ownership even for previously loaded translation state. */
    public function assertOwner(Model $owner, TranslationDefinition $definition): void
    {
        if ($this->disabled()) {
            $this->legacy->assertOwner($owner, $definition);
        } elseif ($definition->ownershipResource === null) {
            $this->assertUnowned($owner);
        } else {
            $this->boundary->assertRecord($owner, $definition->ownershipResource);
        }
    }

    /** Reload immutable structural facts under their actual source transaction lock. */
    public function lockOwner(Model $owner, TranslationDefinition $definition): Model
    {
        if ($this->disabled()) {
            return $this->legacy->lockOwner($owner, $definition);
        }
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

    /** @return array<string, int|string|null> */
    public function childAttributes(Model $owner, TranslationDefinition $definition): array
    {
        if ($this->disabled()) {
            return $this->legacy->childAttributes($owner, $definition);
        }
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
        if ($this->disabled()) {
            return $this->legacy->partitionColumns($definition);
        }
        if ($definition->ownershipResource === null || $this->resources->get($definition->ownershipResource)->ownership->strategy === 'unscoped') {
            return [];
        }
        $this->readiness->assertActive($definition->ownershipResource);

        return ['owner_scope_key'];
    }

    /** Include admitted generations in translation cache and logical-group identities. */
    public function partitionKey(Model $owner, TranslationDefinition $definition): string
    {
        if ($this->disabled()) {
            return $this->legacy->partitionKey($owner, $definition);
        }
        $attributes = $this->childAttributes($owner, $definition);
        $column = match (true) {
            $definition instanceof SelfTranslationDefinition => $definition->groupKey,
            $definition instanceof RelatedTranslationDefinition => $definition->ownerKey,
            default => $owner->getKeyName(),
        };
        $identity = hash('sha256', json_encode([$owner->getConnection()->getName(), $owner->getTable(), $attributes, $owner->getRawOriginal($column)], JSON_THROW_ON_ERROR));

        return $definition->ownershipResource === null ? $identity : $this->boundary->key($definition->ownershipResource, $identity);
    }

    /** Preserve unowned models without interpreting claimed physical storage as global. */
    private function assertUnowned(Model $model): void
    {
        if ($model instanceof Ownable && $model::ownershipDefinition()->strategy !== 'unscoped') {
            throw new OwnershipConfigurationInvalid('Declare the translation resource for an owned model.');
        }
        $this->resources->assertUnclaimed($model);
        $this->storage->assertUnadopted($model->getConnection(), physicalTable: $model->getTable());
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
