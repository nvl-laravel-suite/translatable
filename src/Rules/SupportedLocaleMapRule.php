<?php

declare(strict_types=1);

namespace Nvl\Translatable\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Validates that an associative payload uses supported, unambiguous locale keys.
 */
final readonly class SupportedLocaleMapRule implements ValidationRule
{
    /**
     * @param  list<string>|null  $supportedLocales
     */
    public function __construct(
        private ?array $supportedLocales = null,
    ) {}

    /**
     * Validate every locale key in a locale-indexed map.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail("The {$attribute} field must be a locale-keyed object.");

            return;
        }

        $supportedLocales = $this->normalizedSupportedLocales();
        $seen = [];

        foreach (array_keys($value) as $locale) {
            if (! is_string($locale)) {
                $fail("The {$attribute} field must use string locale keys.");

                continue;
            }

            try {
                $normalizedLocale = (new LocaleCode($locale))->value;
            } catch (InvalidLocaleException) {
                $fail("The {$attribute} field contains an invalid locale [{$locale}].");

                continue;
            }

            if (! in_array($normalizedLocale, $supportedLocales, true)) {
                $fail("The {$attribute} field contains an unsupported locale [{$locale}].");

                continue;
            }

            if (in_array($normalizedLocale, $seen, true)) {
                $fail("The {$attribute} field contains duplicate locale [{$locale}].");

                continue;
            }

            $seen[] = $normalizedLocale;
        }
    }

    /**
     * Return normalized supported locales from the explicit or package configuration.
     *
     * @return list<string>
     */
    private function normalizedSupportedLocales(): array
    {
        $configured = $this->supportedLocales
            ?? config('translatable.locales', ['en']);

        if (! is_array($configured) || $configured === []) {
            throw new TranslatableException(
                'SupportedLocaleMapRule requires at least one configured locale.',
            );
        }

        $locales = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Every SupportedLocaleMapRule locale must be a string.',
                );
            }

            $normalized = (new LocaleCode($locale))->value;

            if (in_array($normalized, $locales, true)) {
                throw new TranslatableException(
                    "Duplicate normalized translation locale [{$normalized}].",
                );
            }

            $locales[] = $normalized;
        }

        return $locales;
    }
}
