<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;

/** @internal */
final readonly class RelatedTranslationJoin
{
    public function __construct(private TranslationOwnership $ownership, private RelatedTranslationStore $store) {}

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function apply(Builder $query, Model $related, RelatedTranslationDefinition $definition, string $locale, string $alias): Builder
    {
        $owner = $query->getModel();
        $base = $query->getQuery();
        $grammar = $base->getGrammar();
        $table = $owner->getTable();
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $alias) !== 1
            || $table === $alias || str_ends_with($table, '.'.$alias) || $base->from !== $table || $base->unions !== null) {
            throw new TranslatableException('Joined translations require a canonical owner query and a safe, unused alias.');
        }
        foreach ($base->joins ?? [] as $join) {
            if (! $join instanceof JoinClause) {
                throw new TranslatableException('Joined translations require expressible Eloquent joins.');
            }
            $source = $join->table instanceof Expression ? $join->table->getValue($grammar) : $join->table;
            if (is_string($source) && ($source === $alias || str_ends_with(strtolower($source), ' as '.strtolower($grammar->wrapTable($alias)))
                || str_ends_with(strtolower($source), ' as '.$alias))) {
                throw new TranslatableException("Joined translation alias [{$alias}] is already in use.");
            }
        }
        $foreignKey = $definition->foreignKey($table);
        $partitions = $this->ownership->partitionColumns($definition);
        $columns = array_values(array_unique([$related->getKeyName(), $foreignKey, $definition->localeKey, ...$partitions, ...$definition->fields]));
        $rankColumn = 'nvl_join_rank';
        while (in_array($rankColumn, $columns, true)) {
            $rankColumn .= '_';
        }
        $candidates = $related->newQuery()->select(array_map($related->qualifyColumn(...), $columns));
        $candidates->getQuery()->where($related->qualifyColumn($definition->localeKey), $locale);
        $partition = implode(', ', array_map(fn (string $column): string => $grammar->wrap($related->qualifyColumn($column)), [$foreignKey, ...$partitions]));
        $id = $grammar->wrap($related->getQualifiedKeyName());
        $rank = $grammar->wrap($rankColumn);
        $this->assertRankSql($partition, $id, $rank);
        $candidates->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY {$id}) AS {$rank}");
        $candidates = $this->store->scopeQuery($candidates, $owner, $definition);
        $first = $owner->getConnection()->query()->fromSub($candidates->toBase(), 'nvl_translation_ranked')->where($rankColumn, 1);

        if ($base->columns === null) {
            $query->select($owner->qualifyColumn('*'));
        }
        $query = $this->ownership->query($query, $definition);
        if ($definition->ownershipResource !== null) {
            $query->useWritePdo();
        }

        return $query->leftJoinSub($first, $alias, static function (JoinClause $join) use ($alias, $definition, $foreignKey, $owner, $partitions): void {
            $join->on("{$alias}.{$foreignKey}", '=', $owner->qualifyColumn($definition->ownerKey));
            foreach ($partitions as $column) {
                $join->on("{$alias}.{$column}", '=', $owner->qualifyColumn($column));
            }
        });
    }

    /**
     * @phpstan-assert literal-string $partition
     * @phpstan-assert literal-string $id
     * @phpstan-assert literal-string $rank
     */
    private function assertRankSql(string $partition, string $id, string $rank): void
    {
        foreach ([$partition, $id, $rank] as $fragment) {
            if (preg_match('/^[A-Za-z0-9_."\x60\[\], ]+$/D', $fragment) !== 1) {
                throw new TranslatableException('Joined translations require safe database identifiers.');
            }
        }
    }
}
