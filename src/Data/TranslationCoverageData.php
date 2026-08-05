<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Locale coverage for one registered resource.
 */
#[TypeScript]
final class TranslationCoverageData extends Data
{
    /**
     * Create locale coverage.
     */
    public function __construct(
        public readonly int $translated,
        public readonly int $missing,
    ) {}
}
