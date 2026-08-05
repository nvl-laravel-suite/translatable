<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Registry metadata and locale coverage for one resource.
 */
#[TypeScript]
final class TranslationResourceSummaryData extends Data
{
    /**
     * Create a resource summary.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $locales
     * @param  list<string>  $searchableColumns
     * @param  list<string>  $displayColumns
     * @param  array<string, TranslationCoverageData>  $coverage
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $model,
        public readonly string $table,
        public readonly string $translationTable,
        public readonly string $storage,
        public readonly string $mutationPolicy,
        public readonly string $keyName,
        public readonly array $fields,
        public readonly array $locales,
        public readonly array $searchableColumns,
        public readonly array $displayColumns,
        public readonly bool $available,
        public readonly int $total,
        public readonly array $coverage,
    ) {}
}
