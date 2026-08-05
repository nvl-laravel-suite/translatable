<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Editable translation state for one registered owner.
 */
#[TypeScript]
final class TranslationResourceRecordData extends Data
{
    /**
     * Create a gathered resource record.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $translations
     * @param  list<string>  $translatedLocales
     * @param  list<string>  $missingLocales
     */
    public function __construct(
        public readonly string $resource,
        public readonly int|string $id,
        public readonly string $label,
        public readonly array $attributes,
        public readonly array $translations,
        public readonly array $translatedLocales,
        public readonly array $missingLocales,
        public readonly string $version,
    ) {}
}
