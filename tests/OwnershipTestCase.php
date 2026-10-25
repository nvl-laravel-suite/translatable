<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\OwnershipCompany;
use Nvl\Translatable\Tests\Support\OwnershipRelatedRecord;
use Nvl\Translatable\Tests\Support\OwnershipSelfRecord;
use Nvl\Translatable\Tests\Support\OwnershipTranslationScenario;
use Orchestra\Testbench\TestCase;

/** Exercises committed ownership and cache behavior without NVL Tenancy or Auth providers. */
abstract class OwnershipTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, SupportServiceProvider::class, OwnershipServiceProvider::class, TranslatableServiceProvider::class];
    }

    /** Configure owned package roots before immutable resource registration. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nvl-core.owners' => [OwnershipCompany::class, OwnershipActor::class],
            'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false,
            'nvl-ownership.central_operations' => ['translation-maintain'],
            'nvl-translatable.locales' => ['en', 'bg'],
            'nvl-translatable.default_locale' => 'en',
            'nvl-translatable.fallback_locales' => ['en'],
        ]);
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            $database = getenv('NVL_OWNERSHIP_TEST_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('Native integration tests require a dedicated nvl_own_test_ database.');
            }
            $app['config']->set('database.connections.sqlite', ['driver' => 'pgsql', 'url' => null, 'host' => '/tmp', 'port' => '5432', 'database' => $database, 'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
        }
    }

    /** Install real package storage and activate through the reviewed coordinator. */
    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::getSchemaBuilder();
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            if (DB::connection()->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_DATABASE')) {
                throw new \RuntimeException('The actual native source differs from the dedicated test database.');
            }
            $schema->dropAllTables();
        }
        foreach (['translation_ownership_companies', 'translation_ownership_actors'] as $table) {
            $schema->create($table, static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        }
        OwnershipCompany::query()->insert([['id' => 7], ['id' => 8]]);
        OwnershipActor::query()->create(['id' => 1]);
        OwnershipTranslationScenario::install($this->app);
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void {}
        });
        $this->app->bind(CentralAccess::class, static fn () => new class implements CentralAccess
        {
            public function assertCanRun(string $operation, Authenticatable $actor): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
        $operation = new OwnershipOperation('adopt-translations', 'operator', '1');
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['fixture'], $this->reviewedMappings(), $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
        $authorization = static fn (Authenticatable|SystemActor $actor, TranslationResourceAbility $ability, ?Model $record): bool => $actor === SystemActor::Background;
        $this->app->make(TranslationResourceRegistry::class)->register('fixture.records', OwnershipRelatedRecord::class, 'Related records', authorization: $authorization);
        $this->app->make(TranslationResourceRegistry::class)->register('fixture.self', OwnershipSelfRecord::class, 'Self records', authorization: $authorization);
    }

    /** @return list<OwnershipMapping> */
    protected function reviewedMappings(): array
    {
        return [];
    }
}
