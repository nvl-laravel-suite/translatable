<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Translatable\Support\LocaleCode;

/** Adapts the validated Translatable catalog to the shared Core locale contract. */
final readonly class LocaleRegistryCatalog implements LocaleCatalog
{
    /** Create the adapter over the existing locale registry. */
    public function __construct(private LocaleRegistry $registry) {}

    /**
     * Return supported translation locales.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        return $this->registry->supported();
    }

    /** Return the default content locale. */
    public function default(): string
    {
        return $this->registry->default();
    }

    /**
     * Return configured fallback locales.
     *
     * @return list<string>
     */
    public function fallbacks(): array
    {
        return $this->registry->fallbacks();
    }

    /** Normalize a locale using the shared code representation. */
    public function normalize(string $locale): string
    {
        return (new LocaleCode($locale))->value;
    }

    /** Determine whether a locale is registered. */
    public function supports(string $locale): bool
    {
        return $this->registry->supports($locale);
    }

    /** Normalize and require catalog membership. */
    public function assertSupported(string $locale): string
    {
        return $this->registry->assertSupported($locale);
    }

    /**
     * Return the deterministic requested, parent, and configured fallback chain.
     *
     * @param  list<mixed>  $additionalFallbacks
     * @return list<string>
     */
    public function chain(string $requestedLocale, array $additionalFallbacks = []): array
    {
        return $this->registry->chain($requestedLocale, $additionalFallbacks);
    }
}
