<?php

declare(strict_types=1);

namespace Nvl\Translatable\Exceptions;

/**
 * Reports a malformed or unsupported content locale.
 */
final class InvalidLocaleException extends TranslatableException
{
    /**
     * Create an exception for a malformed locale.
     */
    public static function malformed(string $locale): self
    {
        return new self("The locale [{$locale}] is not a valid locale code.");
    }

    /**
     * Create an exception for an unsupported locale.
     *
     * @param  list<string>  $supportedLocales
     */
    public static function unsupported(string $locale, array $supportedLocales): self
    {
        return new self(sprintf(
            'The locale [%s] is not supported. Supported locales: %s.',
            $locale,
            implode(', ', $supportedLocales),
        ));
    }
}
