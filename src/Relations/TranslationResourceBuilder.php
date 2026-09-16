<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationOwnership;
use Nvl\Translatable\TranslationDefinition;

/**
 * Applies central-resource ownership after every ordinary scope and deferred query callback.
 *
 * @internal
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class TranslationResourceBuilder extends Builder
{
    private ?TranslationOwnership $translationOwnership = null;

    private ?TranslationDefinition $translationDefinition = null;

    private ?Model $canonicalModel = null;

    private string $resourceKey = '';

    /**
     * Preserve a fully materialized central query while installing its final boundary.
     *
     * @param  Builder<TModel>  $query
     */
    private function __construct(Builder $query)
    {
        parent::__construct($query->getQuery());
        $this->setModel($query->getModel());
        $this->setEagerLoads($query->getEagerLoads());
    }

    /**
     * Convert a materialized central query into an operation-local guarded builder.
     *
     * @template TGuarded of Model
     *
     * @param  Builder<TGuarded>  $query
     * @return self<TGuarded>
     */
    public static function guarded(
        Builder $query,
        string $resourceKey,
        Model $canonicalModel,
        TranslationDefinition $definition,
        TranslationOwnership $ownership,
    ): self {
        $builder = new self($query);
        $builder->resourceKey = $resourceKey;
        $builder->canonicalModel = $canonicalModel;
        $builder->translationDefinition = $definition;
        $builder->translationOwnership = $ownership;

        return $builder;
    }

    /**
     * Materialize late scopes and callbacks before applying the final ownership predicate.
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
        $builder->assertCanonicalStorage();

        if (! $builder->translationOwnership instanceof TranslationOwnership
            || ! $builder->translationDefinition instanceof TranslationDefinition) {
            throw TranslationResourceException::invalid('Translation resource ownership is not configured.');
        }

        $builder->translationOwnership->query($builder, $builder->translationDefinition);

        return $builder;
    }

    /** Reject late replacement away from the independently declared canonical storage. */
    private function assertCanonicalStorage(): void
    {
        if (! $this->canonicalModel instanceof Model) {
            throw TranslationResourceException::invalid('Translation resource storage is not configured.');
        }
        $model = $this->getModel();
        $base = $this->getQuery();

        if ($model::class !== $this->canonicalModel::class
            || $model->getTable() !== $this->canonicalModel->getTable()
            || $model->getConnection() !== $this->canonicalModel->getConnection()
            || $base->getConnection() !== $this->canonicalModel->getConnection()
            || $base->from !== $this->canonicalModel->getTable()
            || $base->unions !== null) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$this->resourceKey}] query scope must preserve its registered model, table, and connection.",
            );
        }
    }
}
