<?php

declare(strict_types=1);

use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;

test('partial consumer config preserves nested translatable defaults', function (): void {
    config()->set('translatable', [
        'middleware' => [
            'query_parameter' => 'translation_locale',
        ],
    ]);

    (new TranslatableServiceProvider(app()))->register();

    expect(config('translatable.middleware.query_parameter'))->toBe('translation_locale')
        ->and(config('translatable.middleware.session_key'))->toBe('content_locale')
        ->and(config('translatable.middleware.cookie_minutes'))->toBe(525_600)
        ->and(config('translatable.transactions.attempts'))->toBe(3);
});

test('configured resources reject malformed metadata instead of silently defaulting it', function (): void {
    config()->set('translatable.resources', [
        'tests.invalid-config' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid config',
            'searchable_columns' => 'slug',
        ],
    ]);
    $provider = new TranslatableServiceProvider(app());

    expect(fn () => $provider->boot(
        app(TranslationResourceRegistry::class),
        app(TypeScriptSourceRegistry::class),
    ))->toThrow(TranslationResourceException::class, 'array of strings');
});
