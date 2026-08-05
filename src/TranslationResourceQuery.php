<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Nvl\Translatable\Exceptions\TranslationResourceException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Carries validated filters for centralized translation gathering.
 */
#[TypeScript]
final class TranslationResourceQuery extends Data
{
    /**
     * Create a translation gathering query.
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $missingLocale = null,
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {
        if ($this->page < 1) {
            throw TranslationResourceException::invalid('Translation resource page must be at least one.');
        }

        if ($this->perPage < 1 || $this->perPage > 500) {
            throw TranslationResourceException::invalid('Translation resource page size must be between 1 and 500.');
        }
    }
}
