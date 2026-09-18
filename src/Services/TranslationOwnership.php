<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\TranslationDefinition;
use stdClass;

/** Connects translation operations to canonical, domain-owned tenant boundaries. */
final readonly class TranslationOwnership
{
    /** Resolve the current scoped boundary and its validated declaration/adoption services. */
    public function __construct(
        private TenantBoundary $boundary,
        private TenantContext $context,
        private TenantResourceRegistry $resources,
        private TenantInstallationState $installation,
        private TenantOwnershipConfiguration $configuration,
    ) {}

    /**
     * Admit canonical storage and apply the declared ownership partition.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, TranslationDefinition $definition): Builder
    {
        if ($definition->ownershipResource === null) {
            $this->assertLegacyDeclaration();
            $connection = $query->getQuery()->getConnection();
            if (! $connection instanceof Connection) {
                throw new TenantConfigurationInvalid('Translation storage requires a Laravel connection.');
            }
            $this->installation->assertUnadopted($connection);

            return $query;
        }

        return $this->boundary->query($query, $definition->ownershipResource);
    }

    /** Validate persisted ownership on every access, including loaded-row fast paths. */
    public function assertOwner(Model $owner, TranslationDefinition $definition): void
    {
        if ($definition->ownershipResource === null) {
            $this->assertLegacyDeclaration();
            $this->installation->assertUnadopted($owner->getConnection());

            return;
        }

        $this->boundary->assertRecord($owner, $definition->ownershipResource);
    }

    /** Reload and lock the persisted owner inside its effective write transaction. */
    public function lockOwner(Model $owner, TranslationDefinition $definition): Model
    {
        $this->assertOwner($owner, $definition);
        if ($this->context->snapshot()->mode !== TenantContextMode::Disabled && $owner->getConnection()->transactionLevel() < 1) {
            throw new TenantBoundaryViolation('Translation writes require an owner connection transaction.');
        }
        $identity = [$owner->getKeyName(), 'tenant_id', 'ownership_key'];
        if ($definition->ownershipResource !== null) {
            $resource = $this->resources->get($definition->ownershipResource);
            if ($resource->kind === TenantResourceKind::Inherited) {
                $model = new $resource->model;
                $relation = Relation::noConstraints(fn () => $model->{$resource->parentRelation}());
                if (! $relation instanceof BelongsTo) {
                    throw new TenantConfigurationInvalid('Translation inheritance requires a belongs-to parent.');
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
            throw new TenantBoundaryViolation('Translation ownership and identity cannot be changed in memory.');
        }
        $key = $owner->getRawOriginal($owner->getKeyName());
        if (! $owner->exists || (! is_string($key) && ! is_int($key))) {
            throw new TenantBoundaryViolation('Translation locking requires a persisted owner.');
        }
        $ownerQuery = $owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class);
        $persisted = $this->query($ownerQuery, $definition)->whereKey($key)->lockForUpdate()->first();
        if (! $persisted instanceof Model) {
            throw new TenantBoundaryViolation('The canonical translation owner is unavailable.');
        }
        foreach ($identity as $column) {
            if ($persisted->getRawOriginal($column) !== $owner->getRawOriginal($column)) {
                throw new TenantBoundaryViolation('Persisted translation ownership or identity has changed.');
            }
        }
        $this->assertOwner($persisted, $definition);

        return $persisted;
    }

    /**
     * Read child ownership from an admitted persisted canonical root, never ambient attributes.
     *
     * @return array{tenant_id?: string|null, ownership_key?: string}
     */
    public function childAttributes(Model $owner, TranslationDefinition $definition): array
    {
        $this->assertOwner($owner, $definition);
        $columns = $this->partitionColumns($definition);
        if ($columns === []) {
            return [];
        }
        $resource = $this->resources->get($definition->ownershipResource ?? throw new TenantConfigurationInvalid);
        $root = $this->canonicalRoot($this->persisted($owner), $resource);
        $tenant = $root->getRawOriginal('tenant_id');
        if (! is_string($tenant) && $tenant !== null) {
            throw new TenantBoundaryViolation('Persisted translation ownership is invalid.');
        }
        $attributes = ['tenant_id' => $tenant];
        if ($columns === ['ownership_key']) {
            $key = $root->getRawOriginal('ownership_key');
            if (! is_string($key) || $key !== ($tenant === null ? 'platform' : 'tenant:'.$tenant)) {
                throw new TenantBoundaryViolation('Persisted translation partition is invalid.');
            }
            $attributes['ownership_key'] = $key;
        }

        return $attributes;
    }

    /**
     * Describe the declared schema; this metadata helper never admits undeclared storage.
     * Call query() or assertOwner() on the actual owner connection before using it for SQL or rows.
     *
     * @return list<string>
     */
    public function partitionColumns(TranslationDefinition $definition): array
    {
        if ($definition->ownershipResource === null) {
            $this->assertLegacyDeclaration();

            return [];
        }
        $this->installation->assertUsable($definition->ownershipResource);
        if ($this->context->snapshot()->mode === TenantContextMode::Disabled) {
            return [];
        }
        $this->configuration->validate();

        return $this->rootColumns($this->resources->get($definition->ownershipResource), []);
    }

    /** Encode canonical storage, ownership and logical identity without delimiter ambiguity. */
    public function partitionKey(Model $owner, TranslationDefinition $definition): string
    {
        $attributes = $this->childAttributes($owner, $definition);
        $persisted = $this->persisted($owner);
        $identityColumn = match (true) {
            $definition instanceof SelfTranslationDefinition => $definition->groupKey,
            $definition instanceof RelatedTranslationDefinition => $definition->ownerKey,
            default => $owner->getKeyName(),
        };

        return hash('sha256', json_encode([
            $owner->getConnection()->getName(), $owner->getTable(), $definition->ownershipResource,
            $attributes, $persisted->getRawOriginal($identityColumn),
        ], JSON_THROW_ON_ERROR));
    }

    /** Reject undeclared ownership outside the inert compatibility runtime. */
    private function assertLegacyDeclaration(): void
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Disabled) {
            throw new TenantConfigurationInvalid('Translation ownership is not declared.');
        }
    }

    /** Read persisted facts on the already admitted owner's actual connection. */
    private function persisted(Model $owner): Model
    {
        $key = $owner->getRawOriginal($owner->getKeyName());
        if (! $owner->exists || (! is_string($key) && ! is_int($key))) {
            throw new TenantBoundaryViolation('Translation ownership requires a persisted owner.');
        }
        $facts = $owner->getConnection()->table($owner->getTable())->where($owner->getKeyName(), $key)->first();
        if ($facts === null) {
            throw new TenantBoundaryViolation('The persisted translation owner is unavailable.');
        }

        return $owner->newFromBuilder($this->attributes($facts));
    }

    /**
     * Normalize database facts to named Eloquent attributes.
     *
     * @return array<string, mixed>
     */
    private function attributes(stdClass $facts): array
    {
        $attributes = [];
        foreach (get_object_vars($facts) as $column => $value) {
            if (! is_string($column)) {
                throw new TenantBoundaryViolation('Translation storage requires named columns.');
            }
            $attributes[$column] = $value;
        }

        return $attributes;
    }

    /**
     * Follow validated roots and reject a heterogeneous polymorphic partition schema.
     *
     * @param  list<string>  $visited
     * @return list<string>
     */
    private function rootColumns(TenantResourceDefinition $resource, array $visited): array
    {
        if (in_array($resource->key, $visited, true)) {
            throw new TenantConfigurationInvalid('Translation ownership contains a cycle.');
        }
        if ($resource->kind !== TenantResourceKind::Inherited) {
            return $resource->allowsPlatformCatalog || $resource->allowsPlatformRows ? ['ownership_key'] : ['tenant_id'];
        }
        $visited[] = $resource->key;
        $parents = $resource->parentResource !== null
            ? [$this->resources->get($resource->parentResource)]
            : array_map(fn (string $class): TenantResourceDefinition => $this->resources->forModel(new $class), $this->configuration->parentTypes($resource->key));
        $columns = null;
        foreach ($parents as $parent) {
            $candidate = $this->rootColumns($parent, $visited);
            if ($columns !== null && $columns !== $candidate) {
                throw new TenantConfigurationInvalid('Translation parents require one consistent partition schema.');
            }
            $columns = $candidate;
        }

        return $columns ?? throw new TenantConfigurationInvalid('Translation ownership requires a canonical root.');
    }

    /** Resolve only persisted canonical parents under the registered polymorphic allowlist. */
    private function canonicalRoot(Model $owner, TenantResourceDefinition $resource): Model
    {
        if ($resource->kind !== TenantResourceKind::Inherited) {
            return $owner;
        }
        $model = new $resource->model;
        $relation = Relation::noConstraints(fn () => $model->{$resource->parentRelation}());
        if (! $relation instanceof BelongsTo) {
            throw new TenantConfigurationInvalid('Translation inheritance requires a belongs-to parent.');
        }
        $class = $relation->getRelated()::class;
        if ($relation instanceof MorphTo) {
            $types = $this->configuration->parentTypes($resource->key);
            $type = $owner->getRawOriginal($relation->getMorphType());
            if (! is_string($type) || ! isset($types[$type])) {
                throw new TenantBoundaryViolation('The canonical translation parent type is not registered.');
            }
            $class = $types[$type];
        }
        $parent = new $class;
        $parentResource = $this->resources->forModel($parent);
        $key = $relation->getOwnerKeyName() ?: $parent->getKeyName();
        $facts = $parent->getConnection()->table($parent->getTable())->where($key, $owner->getRawOriginal($relation->getForeignKeyName()))->first();
        if ($facts === null) {
            throw new TenantBoundaryViolation('The canonical translation parent is unavailable.');
        }
        $parent = $parent->newFromBuilder($this->attributes($facts));
        $this->boundary->assertRecord($parent, $parentResource->key);

        return $this->canonicalRoot($parent, $parentResource);
    }
}
