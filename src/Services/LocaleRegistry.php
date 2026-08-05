<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Owns the configured supported locales and deterministic fallback order.
 */
final readonly class LocaleRegistry
{
    /**
     * Create the locale registry.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Return every configured supported locale.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        $configured = $this->config->get('translatable.locales', ['en']);

        if (! is_array($configured) || $configured === []) {
            throw new TranslatableException(
                'The translatable.locales configuration must contain at least one locale.',
            );
        }

        $locales = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Every translatable.locales value must be a string.',
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

    /**
     * Return every configured fallback locale.
     *
     * @return list<string>
     */
    public function fallbacks(): array
    {
        $configured = $this->config->get('translatable.fallback_locales', []);

        if (! is_array($configured)) {
            throw new TranslatableException(
                'The translatable.fallback_locales configuration must be an array.',
            );
        }

        $fallbacks = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Every translatable fallback locale must be a string.',
                );
            }

            $normalized = $this->assertSupported($locale);

            if (in_array($normalized, $fallbacks, true)) {
                throw new TranslatableException(
                    "Duplicate normalized translation fallback locale [{$normalized}].",
                );
            }

            $fallbacks[] = $normalized;
        }

        return $fallbacks;
    }

    /**
     * Return the configured default content locale.
     */
    public function default(): string
    {
        $configured = $this->config->get('translatable.default_locale', 'en');

        if (! is_string($configured)) {
            throw new TranslatableException(
                'The translatable.default_locale configuration value must be a string.',
            );
        }

        return $this->assertSupported($configured);
    }

    /**
     * Determine whether a locale is configured as supported.
     */
    public function supports(string $locale): bool
    {
        try {
            $normalized = (new LocaleCode($locale))->value;
        } catch (InvalidLocaleException) {
            return false;
        }

        return in_array($normalized, $this->supported(), true);
    }

    /**
     * Normalize and assert that a locale is supported.
     *
     * @throws InvalidLocaleException
     */
    public function assertSupported(string $locale): string
    {
        $normalized = (new LocaleCode($locale))->value;

        if (! in_array($normalized, $this->supported(), true)) {
            throw InvalidLocaleException::unsupported($normalized, $this->supported());
        }

        return $normalized;
    }

    /**
     * Build a deterministic locale chain beginning with the requested locale.
     *
     * @param  list<mixed>  $additionalFallbacks
     * @return list<string>
     */
    public function chain(string $requestedLocale, array $additionalFallbacks = []): array
    {
        $requested = $this->assertSupported($requestedLocale);
        $candidates = [$requested];
        $segments = explode('-', $requested);

        while (count($segments) > 1) {
            array_pop($segments);
            $parent = implode('-', $segments);

            if ($this->supports($parent)) {
                $candidates[] = $parent;
            }
        }

        foreach ([...$additionalFallbacks, ...$this->fallbacks(), $this->default()] as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Every additional translation fallback locale must be a string.',
                );
            }

            $candidates[] = $this->assertSupported($locale);
        }

        return array_values(array_unique($candidates));
    }
}
