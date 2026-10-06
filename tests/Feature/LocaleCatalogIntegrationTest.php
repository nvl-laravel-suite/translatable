<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Locales\LocaleCatalogDiagnostics;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Providers\TranslatableLocaleServiceProvider;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Services\LocaleRegistryCatalog;
use Nvl\Translatable\Tests\Support\TestTranslatableModelTranslation;

it('exposes the configured translation catalog through the Core contract', function (): void {
    config([
        'nvl-translatable.locales' => ['en', 'zh', 'zh-Hant', 'zh-Hant-TW', 'bg'],
        'nvl-translatable.default_locale' => 'bg',
        'nvl-translatable.fallback_locales' => ['en'],
        'nvl-primitives.locales.supported' => ['fr'],
    ]);
    $catalog = app(LocaleCatalog::class);

    expect($catalog->supported())->toBe(app(LocaleRegistry::class)->supported())
        ->and($catalog->chain('zh_hant_tw'))->toBe(['zh-Hant-TW', 'zh-Hant', 'zh', 'en', 'bg'])
        ->and((new LocaleCatalogDiagnostics(app('config'), $catalog))->inspect()['errors'])
        ->toContain('nvl-primitives.locales.supported conflicts with the selected LocaleCatalog.');
});

it('derives new translation defaults from the host application without invented locales', function (): void {
    config([
        'app.locale' => 'de_DE',
        'app.fallback_locale' => 'fr',
        'nvl-translatable.locales' => null,
        'nvl-translatable.default_locale' => null,
        'nvl-translatable.fallback_locales' => null,
        'nvl-primitives.locales.supported' => ['en', 'bg'],
    ]);

    expect(app(LocaleCatalog::class)->supported())->toBe(['de-DE', 'fr'])
        ->and(app(LocaleCatalog::class)->default())->toBe('de-DE')
        ->and(app(LocaleCatalog::class)->fallbacks())->toBe(['fr']);
});

it('keeps an explicitly bound host catalog across either locale provider order', function (): void {
    $host = new LocaleRegistryCatalog(new LocaleRegistry(new Repository([
        'nvl-translatable' => ['locales' => ['fr'], 'default_locale' => 'fr', 'fallback_locales' => []],
    ])));
    app()->instance(LocaleCatalog::class, $host);

    (new TranslatableLocaleServiceProvider(app()))->register();
    (new LocaleServiceProvider(app()))->register();

    expect(app(LocaleCatalog::class))->toBe($host);
});

it('selects Translatable after either provider order and replaces an already resolved Core default', function (bool $resolveCoreFirst): void {
    $application = new Application;
    $application->instance('config', new Repository([
        'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
        'nvl-translatable' => ['locales' => ['fr'], 'default_locale' => 'fr', 'fallback_locales' => []],
    ]));

    if ($resolveCoreFirst) {
        $application->register(LocaleServiceProvider::class);
        expect($application->make(LocaleCatalog::class)->default())->toBe('en');
    }

    $application->register(TranslatableLocaleServiceProvider::class);
    $application->register(LocaleServiceProvider::class);

    expect($application->make(LocaleCatalog::class)->supported())->toBe(['fr']);
})->with([false, true]);

it('respects resource narrowing without requiring excluded global fallback locales', function (): void {
    config([
        'nvl-translatable.locales' => ['en', 'bg', 'en-GB'],
        'nvl-translatable.default_locale' => 'en',
        'nvl-translatable.fallback_locales' => ['en'],
    ]);
    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        foreignKey: 'article_id',
        fields: ['title'],
        locales: ['bg'],
    );

    expect($definition->localeChain('bg'))->toBe(['bg']);
});

it('keeps explicit content locale distinct from UI locale and isolates scoped instances', function (): void {
    config(['nvl-translatable.locales' => ['en', 'bg'], 'nvl-translatable.default_locale' => 'en']);
    app()->setLocale('en');
    $first = app(ContentLocale::class);
    $first->set('bg');

    expect($first->get())->toBe('bg')->and(app()->getLocale())->toBe('en');

    $first->reset();
    app()->forgetScopedInstances();
    $second = app(ContentLocale::class);

    expect($second)->not->toBe($first)->and($second->get())->toBe('en')->and($second->isSet())->toBeFalse();
});
