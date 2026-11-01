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
        'fallback' => [
            'on_null' => false,
        ],
    ]);

    (new TranslatableServiceProvider(app()))->register();

    expect(config('nvl-translatable.fallback.on_null'))->toBeFalse()
        ->and(config('nvl-translatable.fallback.policy'))->toBe('configured')
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

test('configured resource diagnostics name their canonical configuration root', function (mixed $resources): void {
    config()->set('nvl-translatable.resources', $resources);
    $provider = new TranslatableServiceProvider(app());

    expect(fn () => $provider->boot(app(TranslationResourceRegistry::class)))
        ->toThrow(TranslationResourceException::class, 'nvl-translatable.resources');
})->with(['nonarray catalog' => ['invalid'], 'nonarray resource' => [['tests.invalid' => 'invalid']]]);
