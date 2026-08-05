<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\SelfTranslationDefinition;

/**
 * Persists and retrieves locale rows grouped in a resource table itself.
 */
final readonly class SelfTranslationStore
{
    /**
     * Create or update one grouped locale row using a race-safe unique-key lookup.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $definition,
        string $locale,
        array $attributes,
    ): Model {
        $groupValue = $owner->translationResourceKey();
        $shared = [];

        foreach ($definition->sharedFields as $field) {
            $shared[$field] = $owner->getAttribute($field);
        }

        $identity = [
            $definition->groupKey => $groupValue,
            $definition->localeKey => $locale,
        ];
        $creationValues = [...$shared, ...$attributes];
        $translation = Model::unguarded(
            fn (): Model => $this->firstOrCreate($owner, $identity, $creationValues),
        );

        foreach ([...$shared, ...$attributes] as $field => $value) {
            $translation->setAttribute($field, $value);
        }

        if ($translation->isDirty()) {
            $translation->save();
        }

        return $translation;
    }

    /**
     * Delete every grouped locale row outside the supplied locale set.
     *
     * @param  list<string>  $locales
     */
    public function deleteExcept(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $definition,
        array $locales,
    ): void {
        if ($locales === [] && ! $definition->allowDeletingLastTranslation) {
            throw new TranslatableException(
                'A self-translatable resource must retain at least one locale row.',
            );
        }

        $query = $owner->newQuery();
        $query->getQuery()->where(
            $definition->groupKey,
            $owner->translationResourceKey(),
        );

        if ($locales !== []) {
            $query->getQuery()->whereNotIn($definition->localeKey, $locales);
        }

        $query->delete();
    }

    /**
     * Delete one grouped locale row while preserving the required final row.
     */
    public function delete(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $definition,
        string $locale,
    ): bool {
        $query = $owner->newQuery();
        $query->getQuery()->where(
            $definition->groupKey,
            $owner->translationResourceKey(),
        );

        $target = clone $query;
        $target->getQuery()->where($definition->localeKey, $locale);

        if (! $target->exists()) {
            return false;
        }

        if (! $definition->allowDeletingLastTranslation && (clone $query)->count() <= 1) {
            throw new TranslatableException(
                'The final locale row of a self-translatable resource cannot be deleted.',
            );
        }

        return $target->delete() > 0;
    }

    /**
     * Return every grouped locale row for one logical resource.
     *
     * @return Collection<int, covariant Model>
     */
    public function rows(Model&SelfTranslatableModel $owner): Collection
    {
        $definition = $owner->translationDefinition();
        $query = $owner->newQuery();
        $query->getQuery()
            ->where($definition->groupKey, $owner->translationResourceKey())
            ->orderBy($definition->localeKey);

        return $query->get();
    }

    /**
     * Find or create a grouped row while containing unique-key races in a savepoint.
     *
     * @param  array<string, int|string>  $identity
     * @param  array<string, mixed>  $values
     */
    private function firstOrCreate(
        Model&SelfTranslatableModel $owner,
        array $identity,
        array $values,
    ): Model {
        if (($translation = $this->find($owner->newQuery(), $identity)) instanceof Model) {
            return $translation;
        }

        try {
            return $owner->newQuery()->withSavepointIfNeeded(
                function () use ($owner, $identity, $values): Model {
                    $translation = $owner->newInstance();

                    foreach ([...$identity, ...$values] as $field => $value) {
                        $translation->setAttribute($field, $value);
                    }

                    if (! $translation->save()) {
                        throw new TranslatableException('The grouped translation row could not be created.');
                    }

                    return $translation;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            $translation = $this->find(
                $owner->newQuery()->useWritePdo(),
                $identity,
            );

            return $translation ?? throw $exception;
        }
    }

    /**
     * Find one row through dynamically declared, validated identity columns.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, int|string>  $identity
     */
    private function find(Builder $query, array $identity): ?Model
    {
        foreach ($identity as $column => $value) {
            $query->getQuery()->where($column, $value);
        }

        return $query->first();
    }
}
