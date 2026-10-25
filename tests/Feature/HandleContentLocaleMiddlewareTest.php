<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cookie;
use Nvl\Translatable\Contracts\ContentLocalePreferenceResolver;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Tests\Fixtures\Consumer\HandleContentLocale;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    config()->set('nvl-translatable.fallback_locales', ['en']);
    config()->set('consumer.content_locale', [
        'query_parameter' => 'content_lang',
        'session_key' => 'content_locale',
        'cookie_name' => 'content_locale',
        'cookie_minutes' => 525_600,
    ]);
    app(ContentLocale::class)->reset();
});

test('the consumer adapter validates its own HTTP preference settings', function (array $configuration, array $query): void {
    config()->set($configuration);

    expect(fn () => app(HandleContentLocale::class)->handle(
        localeRequest(query: $query),
        static fn (): Response => new Response('ok'),
    ))->toThrow(TranslatableException::class, 'consumer.content_locale');
})->with([
    'invalid query setting' => [['consumer.content_locale.query_parameter' => ''], []],
    'invalid cookie duration' => [['consumer.content_locale.cookie_minutes' => 0], ['content_lang' => 'bg']],
]);

test('HTTP locale middleware and its preferences are owned by the consumer fixture', function (): void {
    expect(class_exists('Nvl\\Translatable\\Middleware\\HandleContentLocale'))->toBeFalse()
        ->and(config('nvl-translatable.middleware'))->toBeNull();
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
