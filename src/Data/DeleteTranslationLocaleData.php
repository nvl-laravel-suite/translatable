<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Versioned request to delete one locale row.
 */
#[TypeScript]
final class DeleteTranslationLocaleData extends Data
{
    /**
     * Create a locale deletion request.
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $expectedVersion,
    ) {}
}
