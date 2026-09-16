<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Persists and retrieves translation rows related to a canonical owner model.
 */
final readonly class RelatedTranslationStore
{
    /** Resolve the scoped domain ownership boundary. */
    public function __construct(private TranslationOwnership $ownership) {}

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
        $relation = $owner->translations();
        $identity = [$definition->localeKey => $locale];
        $translation = Model::unguarded(
            fn (): Model => $this->firstOrCreate($relation, $identity, $attributes),
        );

        foreach ($attributes as $field => $value) {
            $translation->setAttribute($field, $value);
        }

        if ($translation->isDirty()) {
            $translation->save();
        }

        return $translation;
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
        $query = $owner->translations();

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
        $query = $owner->translations();
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
            if ($owner->isDirty([$owner->getKeyName(), $definition->ownerKey, 'tenant_id', 'ownership_key'])) {
                throw new TenantBoundaryViolation('Translation owner identity cannot be changed in memory.');
            }
            $key = $owner->getConnection()->table($owner->getTable())
                ->where($owner->getKeyName(), $owner->getRawOriginal($owner->getKeyName()))
                ->value($definition->ownerKey);
            if ($key !== $owner->getRawOriginal($definition->ownerKey)) {
                throw new TenantBoundaryViolation('The canonical translation owner identity has changed.');
            }
        }

        return [$definition->foreignKey($owner->getTable()) => $key, ...$attributes];
    }

    /**
     * Validate all loaded children against one admitted canonical owner in memory.
     *
     * @param  Collection<int, Model>  $rows
     */
    public function assertRows(Model $owner, RelatedTranslationDefinition $definition, Collection $rows): void
    {
        $identity = $this->ownerIdentity($owner, $definition);
        $canonical = new $definition->translationModel;
        if ($canonical->getConnectionName() === null) {
            $canonical->setConnection($owner->getConnectionName());
        }
        $this->scopeQuery($canonical->newQuery(), $owner, $definition);
        foreach ($rows as $row) {
            if ($row::class !== $canonical::class || $row->getTable() !== $canonical->getTable()
                || $row->getConnection() !== $canonical->getConnection()) {
                throw new TenantBoundaryViolation('Loaded translations do not use canonical related storage.');
            }
            foreach ($identity as $column => $value) {
                if ($row->getAttribute($column) !== $value || $row->getRawOriginal($column) !== $value) {
                    throw new TenantBoundaryViolation('Loaded translations do not belong to the canonical owner.');
                }
            }
        }
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
        if ($definition->ownershipResource === null) {
            $this->ownership->query($owner->newQuery(), $definition);

            return $this->ownership->query($query, $definition);
        }
        $canonical = new $definition->translationModel;
        if ($query->getModel()::class !== $canonical::class || $query->getModel()->getTable() !== $canonical->getTable()
            || $query->getQuery()->from !== $canonical->getTable()
            || $query->getQuery()->getConnection() !== $owner->getConnection()) {
            throw new TenantBoundaryViolation('Translation relations require canonical owner storage.');
        }
        $owners = $this->ownership->query($owner->newQuery(), $definition);
        $owners->select($owner->qualifyColumn($definition->ownerKey));
        $owners->whereColumn($owner->qualifyColumn($definition->ownerKey), $query->qualifyColumn($definition->foreignKey($owner->getTable())));
        foreach ($this->ownership->partitionColumns($definition) as $column) {
            $owners->whereColumn($owner->qualifyColumn($column), $query->qualifyColumn($column));
        }
        $query->whereExists($owners->toBase());

        return $query;
    }

    /**
     * Find or create a related row while containing unique-key races in a savepoint.
     *
     * @param  HasMany<Model, *>  $relation
     * @param  array<string, string>  $identity
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
                    $translation = $relation->make();

                    foreach ([...$identity, ...$values] as $field => $value) {
                        $translation->setAttribute($field, $value);
                    }

                    if ($relation->save($translation) === false) {
                        throw new TranslatableException('The related translation row could not be created.');
                    }

                    return $translation;
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
     * @param  array<string, string>  $identity
     */
    private function find(HasMany $relation, array $identity): ?Model
    {
        $lookup = clone $relation;

        foreach ($identity as $column => $value) {
            $lookup->getQuery()->getQuery()->where($column, $value);
        }

        return $lookup->first();
    }
}
