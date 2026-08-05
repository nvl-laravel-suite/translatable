<?php

declare(strict_types=1);

namespace Nvl\Translatable\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Nvl\Translatable\Contracts\ContentLocalePreferenceResolver;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and persists a request's content locale through configurable preference sources.
 */
final readonly class HandleContentLocale
{
    /**
     * Create the content-locale middleware.
     */
    public function __construct(
        private ContentLocale $contentLocale,
        private LocaleRegistry $locales,
        private ContentLocalePreferenceResolver $preferences,
        private Repository $config,
    ) {}

    /**
     * Resolve the request content locale before continuing the middleware pipeline.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $queryParameter = $this->nullableStringConfig(
            'translatable.middleware.query_parameter',
            'content_lang',
        );

        if ($queryParameter !== null) {
            $requestedLocale = $request->query($queryParameter);

            if (is_string($requestedLocale) && $this->locales->supports($requestedLocale)) {
                $this->persist($request, $requestedLocale);

                return $next($request);
            }
        }

        $preferredLocale = $this->preferences->resolve();

        if (is_string($preferredLocale) && $this->locales->supports($preferredLocale)) {
            $this->persist($request, $preferredLocale);

            return $next($request);
        }

        $sessionKey = $this->nullableStringConfig(
            'translatable.middleware.session_key',
            'content_locale',
        );

        if ($sessionKey !== null && $request->hasSession()) {
            $sessionLocale = $request->session()->get($sessionKey);

            if (is_string($sessionLocale) && $this->locales->supports($sessionLocale)) {
                $this->contentLocale->set($sessionLocale);

                return $next($request);
            }
        }

        $cookieName = $this->nullableStringConfig(
            'translatable.middleware.cookie_name',
            'content_locale',
        );

        if ($cookieName !== null) {
            $cookieLocale = $request->cookie($cookieName);

            if (is_string($cookieLocale) && $this->locales->supports($cookieLocale)) {
                $this->persist($request, $cookieLocale);

                return $next($request);
            }
        }

        return $next($request);
    }

    /**
     * Persist a supported locale to request context, session, and cookie.
     */
    private function persist(Request $request, string $locale): void
    {
        $normalizedLocale = $this->locales->assertSupported($locale);
        $this->contentLocale->set($normalizedLocale);

        $sessionKey = $this->nullableStringConfig(
            'translatable.middleware.session_key',
            'content_locale',
        );

        if ($sessionKey !== null && $request->hasSession()) {
            $request->session()->put($sessionKey, $normalizedLocale);
        }

        $cookieName = $this->nullableStringConfig(
            'translatable.middleware.cookie_name',
            'content_locale',
        );

        if ($cookieName !== null) {
            Cookie::queue(
                $cookieName,
                $normalizedLocale,
                $this->positiveIntegerConfig(
                    'translatable.middleware.cookie_minutes',
                    525_600,
                ),
            );
        }
    }

    /**
     * Return an optional non-empty string configuration value.
     */
    private function nullableStringConfig(string $key, string $default): ?string
    {
        $value = $this->config->get($key, $default);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new TranslatableException(
                "The {$key} value must be a non-empty string or null.",
            );
        }

        return $value;
    }

    /**
     * Return a positive integer configuration value.
     */
    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new TranslatableException(
                "The {$key} value must be a positive integer.",
            );
        }

        return $value;
    }
}
