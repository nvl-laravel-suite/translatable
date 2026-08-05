<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;

beforeEach(function (): void {
    Config::set('translatable.locales', ['en', 'bg', 'en-GB']);
    Config::set('translatable.fallback_locales', ['en']);
    $this->service = app(ContentLocale::class);
    $this->service->reset();
});

test('it falls back to the supported application locale', function (): void {
    App::setLocale('bg');

    expect($this->service->isSet())->toBeFalse()
        ->and($this->service->get())->toBe('bg')
        ->and($this->service->get())->toBe('bg');
});

test('it uses the configured content default when the application locale is unsupported', function (): void {
    App::setLocale('invalid_locale');
    Config::set('translatable.default_locale', 'bg');
    Config::set('app.fallback_locale', 'en');

    expect($this->service->get())->toBe('bg');
});

test('it accepts enum strings and normalized regional locales', function (): void {
    expect($this->service->setFromString('en_gb'))->toBeTrue()
        ->and($this->service->get())->toBe('en-GB')
        ->and($this->service->isSet())->toBeTrue();
});

test('it rejects malformed or unsupported locales without changing state', function (): void {
    expect($this->service->setFromString('invalid_locale'))->toBeFalse()
        ->and($this->service->setFromString('fr'))->toBeFalse()
        ->and($this->service->isSet())->toBeFalse();
});

test('it resets request-scoped locale state', function (): void {
    App::setLocale('en');
    $this->service->set('bg');

    expect($this->service->get())->toBe('bg');

    $this->service->reset();

    expect($this->service->get())->toBe('en')
        ->and($this->service->isSet())->toBeFalse();
});

test('it restores locale state after a temporary locale callback', function (): void {
    $this->service->set('en');

    $result = $this->service->withLocale('bg', function (): string {
        expect($this->service->get())->toBe('bg');

        return 'executed';
    });

    expect($result)->toBe('executed')
        ->and($this->service->get())->toBe('en');
});

test('it provides configured locale options', function (): void {
    $this->service->set('bg');
    $options = $this->service->options();

    expect($options)->toHaveCount(3)
        ->and(collect($options)->firstWhere('value', 'bg')['active'])->toBeTrue()
        ->and(collect($options)->firstWhere('value', 'en')['active'])->toBeFalse();
});

test('it builds global fallback chains with locale parents and the configured default', function (): void {
    Config::set('translatable.locales', ['en', 'zh', 'zh-Hant', 'zh-Hant-TW', 'bg']);
    Config::set('translatable.default_locale', 'bg');
    Config::set('translatable.fallback_locales', ['en']);

    expect(app(LocaleRegistry::class)->chain('zh-Hant-TW'))->toBe([
        'zh-Hant-TW',
        'zh-Hant',
        'zh',
        'en',
        'bg',
    ]);
});
