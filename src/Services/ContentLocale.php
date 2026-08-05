<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Context;
use Nvl\Translatable\Enums\Locale;

/**
 * Manages the request-scoped content locale independently from Laravel's UI locale.
 */
final readonly class ContentLocale
{
    public const string CONTEXT_KEY = 'nvl.content_locale';

    public const string EXPLICIT_CONTEXT_KEY = 'nvl.content_locale.explicit';

    /**
     * Create the request-scoped content locale service.
     */
    public function __construct(
        private LocaleRegistry $locales,
        private Repository $config,
    ) {}

    /**
     * Return the current normalized content locale.
     */
    public function get(): string
    {
        $contextLocale = Context::get(self::CONTEXT_KEY);

        if (is_string($contextLocale) && $this->locales->supports($contextLocale)) {
            return $this->locales->assertSupported($contextLocale);
        }

        $applicationLocale = App::getLocale();

        if ($this->locales->supports($applicationLocale)) {
            return $this->locales->assertSupported($applicationLocale);
        }

        return $this->locales->default();
    }

    /**
     * Explicitly set the current content locale.
     */
    public function set(Locale|string $locale): self
    {
        $value = $locale instanceof Locale ? $locale->value : $locale;
        $normalized = $this->locales->assertSupported($value);

        Context::add(self::CONTEXT_KEY, $normalized);
        Context::add(self::EXPLICIT_CONTEXT_KEY, true);

        return $this;
    }

    /**
     * Attempt to set the content locale from a string.
     */
    public function setFromString(string $locale): bool
    {
        if (! $this->locales->supports($locale)) {
            return false;
        }

        $this->set($locale);

        return true;
    }

    /**
     * Determine whether the locale was explicitly set for this request.
     */
    public function isSet(): bool
    {
        return Context::get(self::EXPLICIT_CONTEXT_KEY, false) === true;
    }

    /**
     * Reset content locale state for the current request.
     */
    public function reset(): self
    {
        Context::forget([self::CONTEXT_KEY, self::EXPLICIT_CONTEXT_KEY]);

        return $this;
    }

    /**
     * Determine whether the current content locale matches a locale.
     */
    public function is(Locale|string $locale): bool
    {
        $value = $locale instanceof Locale ? $locale->value : $locale;

        return $this->get() === $this->locales->assertSupported($value);
    }

    /**
     * Determine whether the current content locale is English.
     */
    public function isEnglish(): bool
    {
        return $this->get() === Locale::EN->value;
    }

    /**
     * Determine whether the current content locale is Bulgarian.
     */
    public function isBulgarian(): bool
    {
        return $this->get() === Locale::BG->value;
    }

    /**
     * Return every configured content locale.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return $this->locales->supported();
    }

    /**
     * Return the configured label for the current content locale.
     */
    public function getLabel(bool $native = false): string
    {
        $locale = $this->get();
        $labelType = $native ? 'native' : 'international';
        $label = $this->config->get("translatable.labels.{$locale}.{$labelType}");

        return is_string($label) ? $label : $locale;
    }

    /**
     * Return frontend locale options with current state.
     *
     * @return list<array{value: string, internationalLabel: string, nativeLabel: string, active: bool}>
     */
    public function options(): array
    {
        $currentLocale = $this->get();

        return array_values(collect($this->available())
            ->map(function (string $locale) use ($currentLocale): array {
                $internationalLabel = $this->config->get(
                    "translatable.labels.{$locale}.international",
                    $locale,
                );
                $nativeLabel = $this->config->get(
                    "translatable.labels.{$locale}.native",
                    $locale,
                );

                return [
                    'value' => $locale,
                    'internationalLabel' => is_string($internationalLabel) ? $internationalLabel : $locale,
                    'nativeLabel' => is_string($nativeLabel) ? $nativeLabel : $locale,
                    'active' => $locale === $currentLocale,
                ];
            })
            ->all());
    }

    /**
     * Execute a callback with a temporary content locale.
     */
    public function withLocale(Locale|string $locale, callable $callback): mixed
    {
        $originalLocale = Context::get(self::CONTEXT_KEY);
        $wasExplicitlySet = $this->isSet();

        $this->set($locale);

        try {
            return $callback();
        } finally {
            if (is_string($originalLocale)) {
                Context::add(self::CONTEXT_KEY, $originalLocale);
            } else {
                Context::forget(self::CONTEXT_KEY);
            }

            if ($wasExplicitlySet) {
                Context::add(self::EXPLICIT_CONTEXT_KEY, true);
            } else {
                Context::forget(self::EXPLICIT_CONTEXT_KEY);
            }
        }
    }
}
