<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Locales\ApplicationLocaleCatalog;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Owns the configured supported locales and deterministic fallback order.
 *
 * @api
 */
final class LocaleRegistry
{
    /**
     * The last raw catalogue and its normalized locales.
     *
     * @var array{raw: array<array-key, mixed>, locales: list<string>}|null
     */
    private ?array $normalized = null;

    /**
     * Create the locale registry.
     */
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Return every configured supported locale, normalizing the catalogue again only when it changes.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        $configured = $this->config->get('nvl-translatable.locales')
            ?? (new ApplicationLocaleCatalog($this->config, allowLegacyCatalog: false))->supported();

        if (! is_array($configured) || $configured === []) {
            throw new TranslatableException(
                'The nvl-translatable.locales configuration must contain at least one locale.',
            );
        }

        if ($this->normalized !== null && $this->normalized['raw'] === $configured) {
            return $this->normalized['locales'];
        }

        $locales = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Every translation locale must be a string.',
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

        $this->normalized = ['raw' => $configured, 'locales' => $locales];

        return $locales;
    }

    /**
     * Return every configured fallback locale, or the application fallbacks this catalogue supports.
     *
     * @return list<string>
     */
    public function fallbacks(): array
    {
        $explicit = $this->config->get('nvl-translatable.fallback_locales');
        $configured = $explicit
            ?? (new ApplicationLocaleCatalog($this->config, allowLegacyCatalog: false))->fallbacks();

        if (! is_array($configured)) {
            throw new TranslatableException(
                'The nvl-translatable.fallback_locales configuration must be an array.',
            );
        }

        $fallbacks = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Every translatable fallback locale must be a string.',
                );
            }

            if ($explicit === null && ! $this->supports($locale)) {
                continue;
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
     * Return the configured default content locale, or the application default when this catalogue
     * supports it and the first supported locale otherwise.
     */
    public function default(): string
    {
        $configured = $this->config->get('nvl-translatable.default_locale');

        if ($configured === null) {
            $derived = (new ApplicationLocaleCatalog($this->config, allowLegacyCatalog: false))->default();

            return $this->supports($derived) ? $this->assertSupported($derived) : $this->supported()[0];
        }

        if (! is_string($configured)) {
            throw new TranslatableException(
                'The nvl-translatable.default_locale configuration value must be a string.',
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
