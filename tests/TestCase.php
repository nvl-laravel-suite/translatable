<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the Translatable package in an isolated Testbench application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Prepare the isolated package database.
     */
    protected function setUp(): void
    {
        parent::setUp();

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
            DataServiceProvider::class,
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
