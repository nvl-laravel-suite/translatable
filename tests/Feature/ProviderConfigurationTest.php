<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;

test('partial consumer config preserves nested translatable defaults', function (): void {
    config()->set('nvl-translatable', [
        'middleware' => [
            'query_parameter' => 'translation_locale',
        ],
    ]);

    (new TranslatableServiceProvider(app()))->register();

    expect(config('nvl-translatable.middleware.query_parameter'))->toBe('translation_locale')
        ->and(config('nvl-translatable.middleware.session_key'))->toBe('content_locale')
        ->and(config('nvl-translatable.middleware.cookie_minutes'))->toBe(525_600)
        ->and(config('nvl-translatable.transactions.attempts'))->toBe(3);
});

test('configured resources reject malformed metadata instead of silently defaulting it', function (): void {
    config()->set('nvl-translatable.resources', [
        'tests.invalid-config' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid config',
            'searchable_columns' => 'slug',
        ],
    ]);
    $provider = new TranslatableServiceProvider(app());

    expect(fn () => $provider->boot(
        app(TranslationResourceRegistry::class),
    ))->toThrow(TranslationResourceException::class, 'array of strings');
});
