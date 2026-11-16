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
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\TenantTranslationOwner;
use RuntimeException;

/** Boots historical tenant-named fixtures through native Ownership admission, on SQLite or the dedicated PostgreSQL package database. */
abstract class TenancyTestCase extends TestCase
{
    /** Whether the package PostgreSQL gate selected its dedicated database for this process. */
    protected static function usesPostgreSql(): bool
    {
        return getenv('DB_CONNECTION') === 'pgsql';
    }

    protected function getPackageProviders($app): array
    {
        return [SupportServiceProvider::class, OwnershipServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (! self::usesPostgreSql()) {
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        }
        $app['config']->set([
            'nvl-core.owners' => [TenantTranslationOwner::class, OwnershipActor::class],
            'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false,
            'nvl-ownership.central_operations' => ['translation-maintain'],
        ]);
    }

    /** Assert the selected driver; on PostgreSQL start from, and leave behind, an empty dedicated package database. */
    protected function setUpDatabase($app)
    {
        $connection = $app['db']->connection();
        $this->assertSame(self::usesPostgreSql() ? 'pgsql' : 'sqlite', $connection->getDriverName());
        if (self::usesPostgreSql()) {
            if (preg_match('/^nvl_translatable_test_[a-f0-9]{8}_ci$/D', $connection->getDatabaseName()) !== 1) {
                throw new RuntimeException('Tenancy fixtures on PostgreSQL require the dedicated package test database.');
            }
            $connection->getSchemaBuilder()->dropAllTables();
            $this->beforeApplicationDestroyed(static fn () => $connection->getSchemaBuilder()->dropAllTables());
        }
        parent::setUpDatabase($app);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::getSchemaBuilder();
        $schema->create('tenant_test_owners', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        $schema->create('translation_ownership_actors', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
        });
        DB::table('tenant_test_owners')->insert([
            ['id' => '00000000-0000-4000-8000-00000000000a'],
            ['id' => '00000000-0000-4000-8000-00000000000b'],
        ]);
        OwnershipActor::query()->create(['id' => 1]);
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
            private bool $enabled = true;

            public function activate(array $payload): void
            {
                $this->enabled = true;
            }

            public function deactivate(): void
            {
                $this->enabled = false;
            }

            public function active(): bool
            {
                return $this->enabled;
            }

            public function data(): array
            {
                return [];
            }
        });
    }
}
