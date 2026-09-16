<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\SelfTranslationDefinition;

/**
 * Persists and retrieves locale rows grouped in a resource table itself.
 */
final readonly class SelfTranslationStore
{
    /** Resolve the scoped domain ownership boundary. */
    public function __construct(private TranslationOwnership $ownership) {}

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

        if (method_exists($translation, 'trashed')
            && method_exists($translation, 'restore')
            && $translation->trashed()) {
            if (! $translation->restore()) {
                throw new TranslatableException('The grouped translation row could not be restored.');
            }
        } elseif ($translation->isDirty()) {
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
        $identity = $this->ownership->childAttributes($owner, $definition);
        $group = $owner->translationResourceKey();
        if ($identity !== []) {
            if ($owner->isDirty([$owner->getKeyName(), $definition->groupKey, $definition->localeKey, 'tenant_id', 'ownership_key'])) {
                throw new TenantBoundaryViolation('Translation group identity cannot be changed in memory.');
            }
            $group = $owner->getConnection()->table($owner->getTable())
                ->where($owner->getKeyName(), $owner->getRawOriginal($owner->getKeyName()))
                ->value($definition->groupKey);
            if ($group !== $owner->getRawOriginal($definition->groupKey)) {
                throw new TenantBoundaryViolation('The canonical translation group identity has changed.');
            }
        }
        $identity[$definition->groupKey] = $group;
        if ($owner->relationLoaded('translations')) {
            $rows = $owner->getRelation('translations');
            if ($rows instanceof Collection) {
                foreach ($rows as $row) {
                    if ($row::class !== $owner::class || $row->getTable() !== $owner->getTable()
                        || $row->getConnection() !== $owner->getConnection()) {
                        throw new TenantBoundaryViolation('Loaded translations do not use canonical group storage.');
                    }
                    foreach ($identity as $column => $value) {
                        if ($row->getAttribute($column) !== $value || $row->getRawOriginal($column) !== $value) {
                            throw new TenantBoundaryViolation('Loaded translations do not belong to the canonical group.');
                        }
                    }
                }

                return $rows;
            }
        }
        $query = $this->ownership->query($owner->newQuery(), $definition);
        foreach ($identity as $column => $value) {
            $query->getQuery()->where($column, $value);
        }
        $query->orderBy($definition->localeKey);

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
        $query = $owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class);

        if (($translation = $this->find($query, $identity)) instanceof Model) {
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
                $owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->useWritePdo(),
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
