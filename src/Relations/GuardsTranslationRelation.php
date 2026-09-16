<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Services\RelatedTranslationStore;

/**
 * Applies the package's owner identity to native lazy, eager and existence relations.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 */
trait GuardsTranslationRelation
{
    /**
     * Build a native relation whose scope, eager, and existence hooks admit child storage.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $foreignKey,
        string $localKey,
        private readonly RelatedTranslationStore $translationStore,
        private readonly RelatedTranslationDefinition $translationDefinition,
    ) {
        parent::__construct($query, $parent, $foreignKey, $localKey);
    }

    /** Add canonical owner and partition predicates to an explicit or lazy relation. */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->query->withGlobalScope('translation_owner', function (Builder $query): void {
                $this->translationStore->scopeQuery($query, $this->parent, $this->translationDefinition);
                $identity = $this->translationStore->ownerIdentity($this->parent, $this->translationDefinition);
                foreach ($identity as $column => $value) {
                    $query->where($this->related->qualifyColumn($column), $value);
                }
            });
        }
        parent::addConstraints();
    }

    /**
     * Admit every owner before Eloquent collects keys for its single batched child query.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $this->translationStore->scopeQuery($this->query, $this->parent, $this->translationDefinition);
        foreach ($models as $model) {
            $this->translationStore->ownerIdentity($model, $this->translationDefinition);
        }
        parent::addEagerConstraints($models);
    }

    /**
     * Preserve the partition correlation when Laravel replaces the relation's existence builder.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<array-key, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        $this->translationStore->scopeQuery($query, $this->parent, $this->translationDefinition);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }
}
