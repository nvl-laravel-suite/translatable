<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Persists and retrieves translation rows related to a canonical owner model.
 *
 * @api
 */
final readonly class RelatedTranslationStore
{
    /** Resolve the scoped domain ownership boundary. */
    public function __construct(
        private TranslationOwnership $ownership,
        private OwnershipBoundary $boundary,
        private OwnershipResourceRegistry $resources,
    ) {}

    /**
     * Create or update one related translation row using a race-safe unique-key lookup.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(
        Model&TranslatableModel $owner,
        RelatedTranslationDefinition $definition,
        string $locale,
        array $attributes,
    ): Model {
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);

        return $this->upsertForOwner($canonicalOwner, $definition, $locale, $attributes);
    }

    /**
     * Persist a locale batch under one freshly acquired canonical owner lock.
     *
     * @param  array<string, array<string, mixed>>  $translations
     *
     * @internal
     */
    public function upsertMany(Model&TranslatableModel $owner, RelatedTranslationDefinition $definition, array $translations): void
    {
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);
        foreach ($translations as $locale => $attributes) {
            $this->upsertForOwner($canonicalOwner, $definition, $locale, $attributes);
        }
    }

    /**
     * Delete every related translation row outside the supplied locale set.
     *
     * @param  list<string>  $locales
     */
    public function deleteExcept(
        Model&TranslatableModel $owner,
        RelatedTranslationDefinition $definition,
        array $locales,
    ): void {
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);
        $query = $this->constrainOwnership(
            $canonicalOwner->translations(),
            $this->ownerIdentity($canonicalOwner, $definition),
        );

        if ($locales !== []) {
            $query->getQuery()->getQuery()->whereNotIn($definition->localeKey, $locales);
        }

        $query->delete();
    }

    /**
     * Delete one exact related translation row.
     */
    public function delete(
        Model&TranslatableModel $owner,
        RelatedTranslationDefinition $definition,
        string $locale,
    ): bool {
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);
        $query = $this->constrainOwnership(
            $canonicalOwner->translations(),
            $this->ownerIdentity($canonicalOwner, $definition),
        );
        $query->getQuery()->getQuery()->where($definition->localeKey, $locale);

        return $query->delete() > 0;
    }

    /**
     * Return every related translation row.
     *
     * @return Collection<int, Model>
     */
    public function rows(Model&TranslatableModel $owner): Collection
    {
        if ($owner->relationLoaded('translations')) {
            $rows = $owner->getRelation('translations');

            if ($rows instanceof Collection) {
                $this->assertRows($owner, $owner->translationDefinition(), $rows);

                return $rows;
            }
        }

        return $owner->translations()->get();
    }

    /** Return freshly admitted related rows in owner input order, batching schema and owner checks.
     *
     * @param  array<array-key, mixed>  $owners  A list of at most 1,000 translatable owner models, validated before reading.
     * @return list<Collection<int, Model>>
     */
    public function rowsForOwners(array $owners): array
    {
        if (! array_is_list($owners) || count($owners) > 1000) {
            throw new TranslatableException('Related translation reads require a list of at most 1,000 owners.');
        }
        /** @var array<string, non-empty-list<array{index: int, owner: Model&TranslatableModel}>> $groups */
        $groups = [];
        foreach ($owners as $index => $owner) {
            if (! $owner instanceof Model || ! $owner instanceof TranslatableModel) {
                throw new TranslatableException('Related translation reads require translatable owner models.');
            }
            $group = hash('sha256', serialize([$owner::class, $owner->getTable(), spl_object_id($owner->getConnection()), $owner->translationDefinition()]));
            $groups[$group][] = ['index' => $index, 'owner' => $owner];
        }
        $result = [];
        foreach ($groups as $entries) {
            $models = array_column($entries, 'owner');
            (new Collection($models))->loadMissing('translations');
            $owner = $models[0];
            $definition = $owner->translationDefinition();
            $canonical = $this->translationModel($owner, $definition);
            $identities = $this->eagerOwnerIdentities($models, $definition);
            if ($definition->ownershipResource === null) {
                $this->ownership->assertUnownedModels([$canonical]);
            } else {
                $this->scopeQuery($canonical->newQuery(), $owner, $definition);
            }
            foreach ($entries as $offset => $entry) {
                $rows = $entry['owner']->getRelation('translations');
                if (! $rows instanceof Collection) {
                    throw new TranslatableException('Related translations require their native model collection.');
                }
                $this->assertRelatedRows($canonical, $rows, $identities[$offset]);
                $result[$entry['index']] = $rows;
            }
        }
        ksort($result);

        return array_values($result);
    }

    /**
     * Admit canonical owner identity once for a related-row read operation.
     *
     * @return array<string, mixed>
     */
    public function ownerIdentity(Model $owner, RelatedTranslationDefinition $definition): array
    {
        $attributes = $this->ownership->childAttributes($owner, $definition);
        $key = $owner->getAttribute($definition->ownerKey);
        if ($attributes !== []) {
            if ($owner->isDirty([$owner->getKeyName(), $definition->ownerKey, 'owner_scope_key', 'owner_reference'])) {
                throw $this->ownership->violation('Translation owner identity cannot be changed in memory.');
            }
            $key = $owner->getConnection()->table($owner->getTable())
                ->where($owner->getKeyName(), $owner->getRawOriginal($owner->getKeyName()))
                ->value($definition->ownerKey);
            if ($key !== $owner->getRawOriginal($definition->ownerKey)) {
                throw $this->ownership->violation('The canonical translation owner identity has changed.');
            }
        }

        return [$definition->foreignKey($owner->getTable()) => $key, ...$attributes];
    }

    /** @param array<int,Model> $owners
     * @return list<array<string,mixed>>
     */
    public function eagerOwnerIdentities(array $owners, RelatedTranslationDefinition $definition): array
    {
        return $this->ownership->eagerOwnerIdentities($owners, $definition) ?? array_values(array_map(fn (Model $owner): array => $this->ownerIdentity($owner, $definition), $owners));
    }

    /**
     * Validate all loaded children against one admitted canonical owner in memory.
     *
     * @param  Collection<int, Model>  $rows
     */
    public function assertRows(Model $owner, RelatedTranslationDefinition $definition, Collection $rows): void
    {
        $canonical = $this->translationModel($owner, $definition);
        if ($definition->ownershipResource === null) {
            $this->ownership->assertUnownedModels([$owner, $canonical]);
            if ($owner->isDirty([$owner->getKeyName(), $definition->ownerKey])) {
                throw $this->ownership->violation('Loaded translation owner identity cannot be changed in memory.');
            }
            $identity = [$definition->foreignKey($owner->getTable()) => $owner->getAttribute($definition->ownerKey)];
        } else {
            $identity = $this->ownerIdentity($owner, $definition);
            $this->scopeQuery($canonical->newQuery(), $owner, $definition);
        }
        $this->assertRelatedRows($canonical, $rows, $identity);
    }

    /**
     * Admit actual child storage and correlate every child to its canonical owner partition.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scopeQuery(Builder $query, Model $owner, RelatedTranslationDefinition $definition): Builder
    {
        if ($query->getQuery()->getConnection() !== $owner->getConnection()) {
            throw $this->ownership->violation('Translation relations require the same actual source connection.');
        }
        if ($definition->ownershipResource === null) {
            $this->ownership->query($owner->newQuery(), $definition);

            return $this->ownership->query($query, $definition);
        }
        $canonical = new $definition->translationModel;
        if ($query->getModel()::class !== $canonical::class || $query->getModel()->getTable() !== $canonical->getTable()
            || $query->getQuery()->from !== $canonical->getTable()
            || $query->getQuery()->getConnection() !== $owner->getConnection()) {
            throw $this->ownership->violation('Translation relations require canonical owner storage.');
        }
        $childResource = $this->resources->forModel($canonical);
        if ($childResource->ownership->strategy !== 'inherited'
            || ! in_array($definition->ownershipResource, $childResource->parents(), true)) {
            throw $this->ownership->violation('Related translation storage must inherit from its canonical owner resource.');
        }
        $this->boundary->query($query, $childResource->key);
        $owners = $this->ownership->query(
            $owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class),
            $definition,
        );
        $owners->select($owner->qualifyColumn($definition->ownerKey));
        $owners->whereColumn($owner->qualifyColumn($definition->ownerKey), $query->qualifyColumn($definition->foreignKey($owner->getTable())));
        foreach ($this->ownership->partitionColumns($definition) as $column) {
            $owners->whereColumn($owner->qualifyColumn($column), $query->qualifyColumn($column));
        }
        $query->whereExists($owners->toBase());

        return $query;
    }

    /** Require the actual parent and canonical related model to share their source connection. */
    private function translationModel(Model $owner, RelatedTranslationDefinition $definition): Model
    {
        $canonical = new $definition->translationModel;
        if ($canonical->getConnectionName() === null) {
            $canonical->setConnection($owner->getConnectionName());
        }
        if ($canonical->getConnection() !== $owner->getConnection()) {
            throw $this->ownership->violation('Translation relations require the same actual source connection.');
        }

        return $canonical;
    }

    /** Validate loaded row identity without triggering model relationship property reads.
     *
     * @param  Collection<int, Model>  $rows
     * @param  array<string, mixed>  $identity
     */
    private function assertRelatedRows(Model $canonical, Collection $rows, array $identity): void
    {
        foreach ($rows as $row) {
            if ($row::class !== $canonical::class || $row->getTable() !== $canonical->getTable()
                || $row->getConnection() !== $canonical->getConnection()) {
                throw $this->ownership->violation('Loaded translations do not use canonical related storage.');
            }
            foreach ($identity as $column => $value) {
                if ($row->getAttribute($column) !== $value || $row->getRawOriginal($column) !== $value) {
                    throw $this->ownership->violation('Loaded translations do not belong to the canonical owner.');
                }
            }
        }
    }

    /**
     * Write one locale while this store's aggregate operation owns the parent lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertForOwner(Model&TranslatableModel $canonicalOwner, RelatedTranslationDefinition $definition, string $locale, array $attributes): Model
    {
        $relation = $canonicalOwner->translations();
        $identity = [
            ...$this->ownerIdentity($canonicalOwner, $definition),
            $definition->localeKey => $locale,
        ];
        $translation = Model::unguarded(
            fn (): Model => $this->firstOrCreate($relation, $identity, $attributes),
        );

        foreach ([...$attributes, ...$identity] as $field => $value) {
            $translation->setAttribute($field, $value);
        }

        if ($translation->isDirty()) {
            $translation->save();
        }

        return $translation;
    }

    /**
     * Find or create a related row while containing unique-key races in a savepoint.
     *
     * @param  HasMany<Model, *>  $relation
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function firstOrCreate(
        HasMany $relation,
        array $identity,
        array $values,
    ): Model {
        if (($translation = $this->find($relation, $identity)) instanceof Model) {
            return $translation;
        }

        try {
            return $relation->getQuery()->withSavepointIfNeeded(
                function () use ($relation, $identity, $values): Model {
                    return $this->ownership->create($relation->getRelated()::class, function (array $assignment) use ($relation, $identity, $values): Model {
                        $translation = $relation->make();

                        foreach ([...$values, ...$identity, ...$assignment] as $field => $value) {
                            $translation->setAttribute($field, $value);
                        }

                        $translation->saveOrFail();

                        return $translation;
                    });
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            $lookup = clone $relation;
            $lookup->getQuery()->useWritePdo();
            $translation = $this->find($lookup, $identity);

            return $translation ?? throw $exception;
        }
    }

    /**
     * Find one related row through dynamically declared, validated identity columns.
     *
     * @param  HasMany<Model, *>  $relation
     * @param  array<string, mixed>  $identity
     */
    private function find(HasMany $relation, array $identity): ?Model
    {
        $lookup = clone $relation;

        foreach ($identity as $column => $value) {
            $lookup->getQuery()->getQuery()->where($column, $value);
        }

        return $lookup->first();
    }

    /**
     * Bind mutation metadata to the owner's complete canonical definition.
     */
    private function canonicalDefinition(
        Model&TranslatableModel $owner,
        RelatedTranslationDefinition $supplied,
    ): RelatedTranslationDefinition {
        $canonical = $owner->translationDefinition();

        if (get_mangled_object_vars($canonical) !== get_mangled_object_vars($supplied)) {
            throw new TranslatableException(
                'Related translation mutations require the owner canonical definition.',
            );
        }

        return $canonical;
    }

    /**
     * Reload the canonical related owner before mutating its translation rows.
     */
    private function lockOwner(
        Model&TranslatableModel $owner,
        RelatedTranslationDefinition $definition,
    ): Model&TranslatableModel {
        $canonicalOwner = $this->ownership->lockOwner($owner, $definition);

        if (! $canonicalOwner instanceof TranslatableModel) {
            throw new TranslatableException('The canonical related translation owner is invalid.');
        }

        return $canonicalOwner;
    }

    /**
     * Apply the exact canonical owner foreign key and ownership columns.
     *
     * @param  HasMany<Model, *>  $relation
     * @param  array<string, mixed>  $identity
     * @return HasMany<Model, *>
     */
    private function constrainOwnership(HasMany $relation, array $identity): HasMany
    {
        foreach ($identity as $column => $value) {
            $relation->getQuery()->getQuery()->where($column, $value);
        }

        return $relation;
    }
}
