<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;

/**
 * Counts one compiled, guarded locale-existence predicate without accepting raw SQL input.
 *
 * @internal
 */
final readonly class LocaleCoverageCount implements Expression
{
    private string $sql;

    /** @var list<mixed> */
    private array $bindings;

    /** Compile one operation-local predicate before retaining its positional bindings. */
    public function __construct(Builder $presence, private string $alias)
    {
        $this->sql = $presence->toSql();
        $this->bindings = $presence->getBindings();
    }

    /** Return the portable conditional count with a grammar-quoted internal alias. */
    public function getValue(Grammar $grammar): string
    {
        return 'COALESCE(SUM(CASE WHEN EXISTS ('.$this->sql.') THEN 1 ELSE 0 END), 0) AS '.$grammar->wrap($this->alias);
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
