<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationWriter;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

test('native writer publishes model, map, actor and revision mutation signatures', function (): void {
    $sync = new ReflectionMethod(TranslationWriter::class, 'sync');
    $deletion = new ReflectionMethod(TranslationWriter::class, 'deleteLocale');

    expect(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $sync->getParameters()))
        ->toBe(['owner', 'translations', 'actor', 'expectedVersion', 'mode', 'resourceKey'])
        ->and((string) $sync->getReturnType())->toBe('Illuminate\Database\Eloquent\Model')
        ->and((string) $deletion->getReturnType())->toBe('bool')
        ->and((string) $sync->getParameters()[2]->getType())->toContain(Authenticatable::class, SystemActor::class);
});

test('provider resolves native writer and bounded gatherer without workflow contracts', function (): void {
    expect($this->app->make(TranslationWriter::class))->toBeInstanceOf(TranslationWriter::class)
        ->and($this->app->make(TranslationResourceGatherer::class))->toBeInstanceOf(TranslationResourceGatherer::class)
        ->and($this->app->bound('Nvl\\Translatable\\Contracts\\SyncTranslationResourceContract'))->toBeFalse()
        ->and($this->app->bound('Nvl\\Translatable\\Contracts\\DeleteTranslationResourceLocaleContract'))->toBeFalse();
});

test('provider preserves a host resource authorizer bound before registration', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $host = Mockery::mock(TranslationResourceAuthorizer::class);
    $consumer->instance(TranslationResourceAuthorizer::class, $host);

    try {
        $consumer->register(TranslatableServiceProvider::class);
        expect($consumer->make(TranslationResourceAuthorizer::class))->toBe($host);
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
