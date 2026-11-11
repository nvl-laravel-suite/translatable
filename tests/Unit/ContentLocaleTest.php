<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Nvl\Translatable\Contracts\ContentLocalePreferenceResolver;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;

beforeEach(function (): void {
    Config::set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    Config::set('nvl-translatable.fallback_locales', ['en']);
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
    Config::set('nvl-translatable.default_locale', 'bg');
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
    Config::set('nvl-translatable.locales', ['en', 'zh', 'zh-Hant', 'zh-Hant-TW', 'bg']);
    Config::set('nvl-translatable.default_locale', 'bg');
    Config::set('nvl-translatable.fallback_locales', ['en']);

    expect(app(LocaleRegistry::class)->chain('zh-Hant-TW'))->toBe([
        'zh-Hant-TW',
        'zh-Hant',
        'zh',
        'en',
        'bg',
    ]);
});

test('it reads the application-owned preference before the application locale', function (): void {
    $resolver = new class implements ContentLocalePreferenceResolver
    {
        public ?string $preference = 'bg';

        public function resolve(): ?string
        {
            return $this->preference;
        }
    };
    app()->instance(ContentLocalePreferenceResolver::class, $resolver);
    app()->forgetScopedInstances();
    App::setLocale('en');
    $service = app(ContentLocale::class);

    expect($service->get())->toBe('bg')
        ->and($service->preferred())->toBe('bg')
        ->and($service->isSet())->toBeFalse()
        ->and($service->is('bg'))->toBeTrue()
        ->and((new TestTranslatableModel)->getCurrentLocale())->toBe('bg')
        ->and((new TestSelfTranslatableModel)->getCurrentLocale())->toBe('bg');

    $service->set('en-GB');
    expect($service->get())->toBe('en-GB')
        ->and((new TestTranslatableModel)->getCurrentLocale())->toBe('en-GB');

    $service->reset();
    $resolver->preference = 'fr';
    expect($service->get())->toBe('en');

    $resolver->preference = null;
    expect($service->get())->toBe('en');
});

test('the deprecated fixed locale predicates follow the configured catalogue', function (): void {
    $this->service->set('bg');

    expect($this->service->isBulgarian())->toBeTrue()
        ->and($this->service->isEnglish())->toBeFalse();

    Config::set('nvl-translatable.locales', ['de']);
    Config::set('nvl-translatable.default_locale', 'de');
    Config::set('nvl-translatable.fallback_locales', []);
    $this->service->reset();

    expect($this->service->isEnglish())->toBeFalse()
        ->and($this->service->isBulgarian())->toBeFalse()
        ->and($this->service->is('de'))->toBeTrue();
});

test('the registry answers the current catalogue after every configuration change', function (): void {
    $registry = app(LocaleRegistry::class);

    expect($registry->supported())->toBe(['en', 'bg', 'en-GB'])
        ->and($registry->supported())->toBe(['en', 'bg', 'en-GB']);

    Config::set('nvl-translatable.locales', ['EN_us', 'de']);
    expect($registry->supported())->toBe(['en-US', 'de'])
        ->and($registry->supports('bg'))->toBeFalse();

    Config::set('nvl-translatable.locales', ['de', 'en_US']);
    expect($registry->supported())->toBe(['de', 'en-US']);

    Config::set('nvl-translatable.locales', ['de', 'DE']);
    expect(fn () => $registry->supported())->toThrow(TranslatableException::class, 'Duplicate normalized translation locale');

    Config::set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    expect($registry->supported())->toBe(['en', 'bg', 'en-GB']);
});
