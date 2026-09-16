<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Services\RelatedTranslationStore;

/**
 * Applies related-translation ownership after every removable scope and caller predicate.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class TranslationRelationBuilder extends Builder
{
    private ?RelatedTranslationStore $translationStore = null;

    private ?Model $translationOwner = null;

    private ?RelatedTranslationDefinition $translationDefinition = null;

    /** @var list<array<string, mixed>> */
    private array $translationOwnerIdentities = [];

    /** @var array{parent: string, child: string}|null */
    private ?array $translationExistenceColumns = null;

    /**
     * Preserve the related model's native query state while installing the package builder.
     *
     * @param  Builder<TModel>  $query
     */
    public function __construct(Builder $query)
    {
        parent::__construct($query->getQuery());
        $this->setModel($query->getModel());
        $this->setEagerLoads($query->getEagerLoads());
        $query->getModel()->registerGlobalScopes($this);
    }

    /**
     * Convert a fresh related-model query into an operation-local guarded builder.
     *
     * @template TRelated of Model
     *
     * @param  Builder<TRelated>  $query
     * @return self<TRelated>
     */
    public static function guarded(
        Builder $query,
        Model $owner,
        RelatedTranslationStore $store,
        RelatedTranslationDefinition $definition,
    ): self {
        $builder = new self($query);
        $builder->translationStore = $store;
        $builder->translationOwner = $owner;
        $builder->translationDefinition = $definition;

        return $builder;
    }

    /**
     * Retain the canonical owner set for one batched eager operation.
     *
     * @param  array<int, Model>  $owners
     * @return self<TModel>
     */
    public function restrictToOwners(array $owners): self
    {
        if (! $this->translationStore instanceof RelatedTranslationStore
            || ! $this->translationDefinition instanceof RelatedTranslationDefinition) {
            throw new LogicException('Translation relation ownership is not configured.');
        }
        $this->translationOwnerIdentities = [];
        foreach ($owners as $owner) {
            $this->translationOwnerIdentities[] = $this->translationStore->ownerIdentity($owner, $this->translationDefinition);
        }

        return $this;
    }

    /**
     * Retain the native existence correlation for final application after caller predicates.
     *
     * @return self<TModel>
     */
    public function restrictToExistence(string $parentColumn, string $childColumn): self
    {
        $this->translationExistenceColumns = ['parent' => $parentColumn, 'child' => $childColumn];

        return $this;
    }

    /**
     * Apply ordinary scopes first, then enforce the non-removable final ownership boundary.
     *
     * @return self<TModel>
     */
    public function applyScopes(): self
    {
        $builder = parent::applyScopes();
        if ($builder === $this) {
            $builder = clone $this;
        }
        $builder->getQuery()->applyBeforeQueryCallbacks();
        if (! $builder->translationStore instanceof RelatedTranslationStore
            || ! $builder->translationOwner instanceof Model
            || ! $builder->translationDefinition instanceof RelatedTranslationDefinition) {
            throw new LogicException('Translation relation ownership is not configured.');
        }
        $identities = $builder->translationOwnerIdentities;
        if ($builder->translationOwner->exists) {
            $identities[] = $builder->translationStore->ownerIdentity(
                $builder->translationOwner,
                $builder->translationDefinition,
            );
        }
        $builder->translationStore->scopeQuery(
            $builder,
            $builder->translationOwner,
            $builder->translationDefinition,
        );
        if ($identities !== []) {
            $builder->where(function (Builder $allowed) use ($builder, $identities): void {
                foreach ($identities as $index => $identity) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $allowed->{$method}(function (Builder $branch) use ($builder, $identity): void {
                        foreach ($identity as $column => $value) {
                            $branch->where($builder->getModel()->qualifyColumn($column), $value);
                        }
                    });
                }
            });
        }
        if ($builder->translationExistenceColumns !== null) {
            $builder->whereColumn(
                $builder->translationExistenceColumns['parent'],
                $builder->translationExistenceColumns['child'],
            );
        }

        return $builder;
    }
}
