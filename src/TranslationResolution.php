<?php

declare(strict_types=1);

namespace Nvl\Translatable;

/**
 * Describes the outcome and provenance of one translated field resolution.
 */
final readonly class TranslationResolution
{
    /**
     * Create a translation resolution result.
     *
     * @param  list<string>  $localeChain
     */
    public function __construct(
        public string $field,
        public string $requestedLocale,
        public ?string $resolvedLocale,
        public mixed $value,
        public array $localeChain,
        public bool $requestedTranslationExists,
    ) {}

    /**
     * Determine whether a fallback locale supplied the value.
     */
    public function usedFallback(): bool
    {
        return $this->resolvedLocale !== null
            && $this->resolvedLocale !== $this->requestedLocale;
    }

    /**
     * Determine whether no locale supplied a usable value.
     */
    public function isMissing(): bool
    {
        return $this->resolvedLocale === null;
    }
}
