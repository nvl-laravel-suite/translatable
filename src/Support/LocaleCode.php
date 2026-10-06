<?php

declare(strict_types=1);

namespace Nvl\Translatable\Support;

use InvalidArgumentException;
use Nvl\Support\Locales\LocaleCode as SharedLocaleCode;
use Nvl\Translatable\Exceptions\InvalidLocaleException;

/**
 * Normalizes and validates BCP-47-compatible locale identifiers.
 */
final readonly class LocaleCode
{
    public string $value;

    /**
     * Create a normalized locale code.
     *
     * @throws InvalidLocaleException
     */
    public function __construct(string $locale)
    {
        try {
            $normalized = (new SharedLocaleCode($locale))->value;
        } catch (InvalidArgumentException) {
            throw InvalidLocaleException::malformed($locale);
        }

        $this->value = $normalized;
    }

    /**
     * Normalize a locale code into a stable BCP-47-compatible representation.
     */
    public static function normalize(string $locale): string
    {
        return SharedLocaleCode::normalize($locale);
    }

    /**
     * Determine whether a normalized value has a valid locale shape.
     */
    public static function isValid(string $locale): bool
    {
        return SharedLocaleCode::isValid($locale);
    }

    /**
     * Return the normalized locale value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
