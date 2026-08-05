<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cookie;
use Nvl\Translatable\Contracts\ContentLocalePreferenceResolver;
use Nvl\Translatable\Middleware\HandleContentLocale;
use Nvl\Translatable\Services\ContentLocale;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('translatable.locales', ['en', 'bg']);
    config()->set('translatable.fallback_locales', ['en']);
    app(ContentLocale::class)->reset();
});

test('query locale takes precedence and persists to the session and cookie', function (): void {
    app()->bind(
        ContentLocalePreferenceResolver::class,
        static fn (): ContentLocalePreferenceResolver => new class implements ContentLocalePreferenceResolver
        {
            /**
             * Return a lower-priority stored preference.
             */
            public function resolve(): ?string
            {
                return 'en';
            }
        },
    );
    $request = localeRequest(
        query: ['content_lang' => 'bg'],
        cookies: ['content_locale' => 'en'],
        sessionLocale: 'en',
    );
    $response = app(HandleContentLocale::class)->handle(
        $request,
        static fn (): Response => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok')
        ->and(app(ContentLocale::class)->get())->toBe('bg')
        ->and($request->session()->get('content_locale'))->toBe('bg')
        ->and(collect(Cookie::getQueuedCookies())->last()?->getValue())->toBe('bg');
});

test('stored preference takes precedence over session and cookie locales', function (): void {
    app()->bind(
        ContentLocalePreferenceResolver::class,
        static fn (): ContentLocalePreferenceResolver => new class implements ContentLocalePreferenceResolver
        {
            /**
             * Return the persisted content-locale preference.
             */
            public function resolve(): ?string
            {
                return 'bg';
            }
        },
    );
    $request = localeRequest(
        cookies: ['content_locale' => 'en'],
        sessionLocale: 'en',
    );

    app(HandleContentLocale::class)->handle(
        $request,
        static fn (): Response => new Response('ok'),
    );

    expect(app(ContentLocale::class)->get())->toBe('bg')
        ->and($request->session()->get('content_locale'))->toBe('bg');
});

test('session and cookie fallbacks accept only supported locales', function (): void {
    $sessionRequest = localeRequest(
        query: ['content_lang' => 'fr'],
        cookies: ['content_locale' => 'bg'],
        sessionLocale: 'en',
    );

    app(HandleContentLocale::class)->handle(
        $sessionRequest,
        static fn (): Response => new Response('session'),
    );

    expect(app(ContentLocale::class)->get())->toBe('en');

    app(ContentLocale::class)->reset();
    $cookieRequest = localeRequest(
        query: ['content_lang' => 'fr'],
        cookies: ['content_locale' => 'bg'],
        sessionLocale: 'fr',
    );

    app(HandleContentLocale::class)->handle(
        $cookieRequest,
        static fn (): Response => new Response('cookie'),
    );

    expect(app(ContentLocale::class)->get())->toBe('bg')
        ->and($cookieRequest->session()->get('content_locale'))->toBe('bg');
});

/**
 * Create a request carrying configurable locale preference sources.
 *
 * @param  array<string, string>  $query
 * @param  array<string, string>  $cookies
 */
function localeRequest(
    array $query = [],
    array $cookies = [],
    ?string $sessionLocale = null,
): Request {
    $request = Request::create('/', parameters: $query, cookies: $cookies);
    $session = new Store('translatable-tests', new ArraySessionHandler(120));

    if ($sessionLocale !== null) {
        $session->put('content_locale', $sessionLocale);
    }

    $request->setLaravelSession($session);

    return $request;
}
