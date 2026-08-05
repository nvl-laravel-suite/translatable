<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Provides the package's built-in English and Bulgarian locale metadata.
 */
enum Locale: string
{
    case EN = 'en';
    case BG = 'bg';

    /**
     * Get the international label for the locale.
     */
    public function internationalLabel(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::BG => 'Bulgarian',
        };
    }

    /**
     * Get the native label for the locale.
     */
    public function nativeLabel(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::BG => 'Български',
        };
    }

    /**
     * Normalize language code.
     */
    public static function normalizeLanguageCode(string $code, string $fallback = 'en'): string
    {
        $code = strtolower(trim($code));

        return self::tryFrom($code) !== null ? $code : $fallback;
    }

    /**
     * Get options array for frontend.
     *
     * @return list<array{value: string, internationalLabel: string, nativeLabel: string, active: bool}>
     */
    public static function options(self $activeLocale = self::EN): array
    {
        return array_map(fn (self $locale) => [
            'value' => $locale->value,
            'internationalLabel' => $locale->internationalLabel(),
            'nativeLabel' => $locale->nativeLabel(),
            'active' => $locale === $activeLocale,
        ], self::cases());
    }

    /**
     * Helper to get Locale from string value safely.
     */
    public static function fromValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtolower($value));
    }
}
