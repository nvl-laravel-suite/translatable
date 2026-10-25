<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\TranslationResourceDefinition;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the Translatable package in an isolated Testbench application.
 */
abstract class TestCase extends Orchestra
{
    /** Retain the published catalog used by the package's established fixtures. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'nvl-translatable.locales' => ['en', 'bg'],
            'nvl-translatable.default_locale' => 'en',
            'nvl-translatable.fallback_locales' => ['en'],
        ]);
    }

    /**
     * Prepare the isolated package database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TranslationResourceAuthorizer::class, static fn () => new class implements TranslationResourceAuthorizer
        {
            public function allows(Authenticatable|SystemActor $actor, TranslationResourceAbility $ability, TranslationResourceDefinition $resource, ?Model $record = null): bool
            {
                return $actor === SystemActor::Background;
            }
        });

        $this->setUpDatabase($this->app);
    }

    /**
     * Return package providers used by the isolated application.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            LocaleServiceProvider::class,
            TranslatableServiceProvider::class,
        ];
    }

    /**
     * Create the package test tables.
     *
     * @param  Application  $app
     */
    protected function setUpDatabase($app)
    {
        $schema = $app['db']->connection()->getSchemaBuilder();

        $schema->dropIfExists('test_translatable_models_i18n');
        $schema->dropIfExists('test_translatable_models');
        $schema->create('test_translatable_models', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->nullable();
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
    }
}
