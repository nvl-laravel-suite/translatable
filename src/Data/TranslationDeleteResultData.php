<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Result of a committed locale deletion.
 */
#[TypeScript]
final class TranslationDeleteResultData extends Data
{
    /**
     * Create a deletion result.
     */
    public function __construct(
        public readonly string $resource,
        public readonly int|string $id,
        public readonly string $locale,
        public readonly bool $deleted,
        public readonly string $version,
    ) {}
}
