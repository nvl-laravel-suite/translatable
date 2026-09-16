<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
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

    /** Add native relationship key predicates before final guarded execution. */
    public function addConstraints(): void
    {
        parent::addConstraints();
    }

    /**
     * Admit every owner before Eloquent collects keys for its single batched child query.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        if (! $this->query instanceof TranslationRelationBuilder) {
            throw new LogicException('Translation eager loading requires its guarded builder.');
        }
        $this->query->restrictToOwners($models);
        parent::addEagerConstraints($models);
    }

    /**
     * Preserve the partition correlation when Laravel replaces the relation's existence builder.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<array-key, string>|string  $columns
     * @return TranslationRelationBuilder<TRelatedModel>
     */
    public function getRelationExistenceQuery(
        Builder $query,
        Builder $parentQuery,
        $columns = ['*'],
    ): TranslationRelationBuilder {
        $guardedQuery = TranslationRelationBuilder::guarded(
            $query,
            $this->parent,
            $this->translationStore,
            $this->translationDefinition,
        );

        $query = parent::getRelationExistenceQuery($guardedQuery, $parentQuery, $columns);
        if ($query !== $guardedQuery) {
            throw new LogicException('Translation existence queries require their guarded builder.');
        }

        return $guardedQuery->restrictToExistence(
            $this->getQualifiedParentKeyName(),
            $this->getExistenceCompareKey(),
        );
    }
}
