<?php

declare(strict_types=1);

namespace Nvl\Translatable\Support;

use Illuminate\Support\Str;
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
        $normalized = self::normalize($locale);

        if (! self::isValid($normalized)) {
            throw InvalidLocaleException::malformed($locale);
        }

        $this->value = $normalized;
    }

    /**
     * Normalize a locale code into a stable BCP-47-compatible representation.
     */
    public static function normalize(string $locale): string
    {
        $segments = explode('-', str_replace('_', '-', Str::of($locale)->trim()->toString()));

        return collect($segments)
            ->map(static function (string $segment, int $index): string {
                if ($index === 0) {
                    return Str::lower($segment);
                }

                if (mb_strlen($segment) === 2 || ctype_digit($segment)) {
                    return Str::upper($segment);
                }

                if (mb_strlen($segment) === 4) {
                    return Str::ucfirst(Str::lower($segment));
                }

                return Str::lower($segment);
            })
            ->implode('-');
    }

    /**
     * Determine whether a normalized value has a valid locale shape.
     */
    public static function isValid(string $locale): bool
    {
        return mb_strlen($locale) <= 35
            && preg_match('/^[a-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $locale) === 1;
    }

    /**
     * Return the normalized locale value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
