<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use ReflectionClass;

/** Boots explicit tenant fixtures without an ambient test transaction. */
abstract class TenancyTestCase extends TestCase
{
    use DatabaseMigrations;

    /** Configure the test-only adoption authorization and maintenance lease. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->instance(PlatformAccess::class, new class implements PlatformAccess
        {
            /** Admit only this fixture's named adoption operation. */
            public function authorize(PlatformOperation $operation): void
            {
                if ($operation->purpose !== 'fixture.adoption' || $operation->actorType !== 'test' || $operation->actorId !== 'fixture') {
                    throw new TenantBoundaryViolation;
                }
            }
        });
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private bool $enabled = true;

            /** @var array<string, mixed> */
            private array $payload = [];

            /** @param array<string, mixed> $payload */
            public function activate(array $payload): void
            {
                $this->enabled = true;
                $this->payload = $payload;
            }

            /** End fixture maintenance. */
            public function deactivate(): void
            {
                $this->enabled = false;
            }

            /** Report the current maintenance state. */
            public function active(): bool
            {
                return $this->enabled;
            }

            /** @return array<string, mixed> */
            public function data(): array
            {
                return $this->payload;
            }
        });
    }

    /** Load the opt-in core schema after Testbench's migration refresh. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
