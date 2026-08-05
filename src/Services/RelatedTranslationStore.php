<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Persists and retrieves translation rows related to a canonical owner model.
 */
final readonly class RelatedTranslationStore
{
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
                return $rows;
            }
        }

        return $owner->translations()->get();
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
