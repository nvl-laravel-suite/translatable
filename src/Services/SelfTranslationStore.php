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
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);
        $shared = [];

        foreach ($definition->sharedFields as $field) {
            $shared[$field] = $canonicalOwner->getAttribute($field);
        }

        $identity = [
            ...$this->ownership->childAttributes($canonicalOwner, $definition),
            $definition->groupKey => $canonicalOwner->translationResourceKey(),
            $definition->localeKey => $locale,
        ];
        $this->groupQuery($canonicalOwner, $definition, withTrashed: true)
            ->lockForUpdate()
            ->get();
        $translation = Model::unguarded(
            fn (): Model => $this->firstOrCreate(
                $canonicalOwner,
                $definition,
                $identity,
                [...$shared, ...$attributes],
            ),
        );

        foreach ([...$shared, ...$attributes, ...$identity] as $field => $value) {
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
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);

        if ($locales === [] && ! $definition->allowDeletingLastTranslation) {
            throw new TranslatableException(
                'A self-translatable resource must retain at least one locale row.',
            );
        }

        $query = $this->groupQuery($canonicalOwner, $definition);
        $rows = (clone $query)->lockForUpdate()->get([$definition->localeKey]);

        if (! $definition->allowDeletingLastTranslation
            && $rows->whereIn($definition->localeKey, $locales)->isEmpty()) {
            throw new TranslatableException(
                'A self-translatable resource must retain at least one locale row.',
            );
        }

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
        $definition = $this->canonicalDefinition($owner, $definition);
        $canonicalOwner = $this->lockOwner($owner, $definition);
        $query = $this->groupQuery($canonicalOwner, $definition);
        $rows = (clone $query)->lockForUpdate()->get();

        if (! $rows->contains($definition->localeKey, $locale)) {
            return false;
        }

        if (! $definition->allowDeletingLastTranslation && $rows->count() <= 1) {
            throw new TranslatableException(
                'The final locale row of a self-translatable resource cannot be deleted.',
            );
        }

        return $query->where($definition->localeKey, $locale)->delete() > 0;
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
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function firstOrCreate(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $definition,
        array $identity,
        array $values,
    ): Model {
        $query = $this->ownership->query(
            $owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class),
            $definition,
        );

        if (($translation = $this->find($query, $identity)) instanceof Model) {
            return $translation;
        }

        try {
            return $owner->newQuery()->withSavepointIfNeeded(
                function () use ($owner, $identity, $values): Model {
                    $translation = $owner->newInstance();

                    foreach ([...$values, ...$identity] as $field => $value) {
                        $translation->setAttribute($field, $value);
                    }

                    $translation->saveOrFail();

                    return $translation;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            $translation = $this->find(
                $this->ownership->query(
                    $owner->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->useWritePdo(),
                    $definition,
                ),
                $identity,
            );

            return $translation ?? throw $exception;
        }
    }

    /**
     * Find one row through dynamically declared, validated identity columns.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $identity
     */
    private function find(Builder $query, array $identity): ?Model
    {
        foreach ($identity as $column => $value) {
            $query->getQuery()->where($column, $value);
        }

        return $query->first();
    }

    /**
     * Bind mutation metadata to the owner's complete canonical definition.
     */
    private function canonicalDefinition(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $supplied,
    ): SelfTranslationDefinition {
        $canonical = $owner->translationDefinition();

        if (get_mangled_object_vars($canonical) !== get_mangled_object_vars($supplied)) {
            throw new TranslatableException(
                'Self-translation mutations require the owner canonical definition.',
            );
        }

        return $canonical;
    }

    /**
     * Reload the canonical representative before mutating its immutable group.
     */
    private function lockOwner(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $definition,
    ): Model&SelfTranslatableModel {
        $canonicalOwner = $this->ownership->lockOwner($owner, $definition);

        if (! $canonicalOwner instanceof SelfTranslatableModel) {
            throw new TranslatableException('The canonical self-translation owner is invalid.');
        }

        return $canonicalOwner;
    }

    /**
     * Build the canonical tenant-local group query while retaining every ordinary scope.
     *
     * @return Builder<Model>
     */
    private function groupQuery(
        Model&SelfTranslatableModel $owner,
        SelfTranslationDefinition $definition,
        bool $withTrashed = false,
    ): Builder {
        $query = $owner->newQuery();

        if ($withTrashed) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $query = $this->ownership->query($query, $definition);
        foreach ([
            ...$this->ownership->childAttributes($owner, $definition),
            $definition->groupKey => $owner->translationResourceKey(),
        ] as $column => $value) {
            $query->getQuery()->where($column, $value);
        }

        return $query;
    }
}
