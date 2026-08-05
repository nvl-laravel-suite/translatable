<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Nvl\Translatable\Enums\TranslationSyncMode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Versioned mutation for one registered translation owner.
 */
#[TypeScript]
final class TranslationMutationData extends Data
{
    /**
     * Create a translation mutation.
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function __construct(
        public readonly array $translations,
        public readonly string $expectedVersion,
        public readonly TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ) {}
}
