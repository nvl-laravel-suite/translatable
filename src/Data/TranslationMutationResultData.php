<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Result of a committed translation synchronization.
 */
#[TypeScript]
final class TranslationMutationResultData extends Data
{
    /**
     * Create a mutation result.
     *
     * @param  list<string>  $locales
     */
    public function __construct(
        public readonly string $resource,
        public readonly int|string $id,
        public readonly array $locales,
        public readonly string $version,
    ) {}
}
