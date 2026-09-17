<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationFixtureAdoptionAdapter;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;

/** Registers deterministic adapters and resources for copied worker processes. */
final class TenancyConsumerServiceProvider extends ServiceProvider
{
    /** Bind the fixture's host-owned directory, authorization, and maintenance ports. */
    public function register(): void
    {
        $this->app->singleton(TenantDirectory::class, static fn (): TenantDirectory => new class implements TenantDirectory
        {
            /** Resolve the fixture's two active tenants only. */
            public function find(TenantId $id): TenantDescriptor
            {
                if (! in_array($id->value, [TenantTranslationScenario::A, TenantTranslationScenario::B], true)) {
                    throw new TenantNotFound;
                }

                return new TenantDescriptor($id, TenantStatus::Active);
            }
        });
        $this->app->singleton(PlatformAccess::class, static fn (): PlatformAccess => new class implements PlatformAccess
        {
            /** Authorize only the fixture adoption operation. */
            public function authorize(PlatformOperation $operation): void
            {
                if ($operation->purpose !== 'fixture.adoption' || $operation->actorType !== 'test' || $operation->actorId !== 'fixture') {
                    throw new TenantBoundaryViolation('The fixture platform operation is not authorized.');
                }
            }
        });
        $this->app->singleton(MaintenanceMode::class, static fn (): MaintenanceMode => new class implements MaintenanceMode
        {
            private bool $enabled = false;

            /** @var array<string, mixed> */
            private array $payload = [];

            /**
             * Start fixture maintenance.
             *
             * @param  array<string, mixed>  $payload
             */
            public function activate(array $payload): void
            {
                $this->enabled = true;
                $this->payload = $payload;
            }

            /** End fixture maintenance. */
            public function deactivate(): void
            {
                $this->enabled = false;
                $this->payload = [];
            }

            /** Report whether fixture maintenance is active. */
            public function active(): bool
            {
                return $this->enabled;
            }

            /**
             * Return the current maintenance payload.
             *
             * @return array<string, mixed>
             */
            public function data(): array
            {
                return $this->payload;
            }
        });
        $this->app->singleton(TenantTranslationFixtureAdoptionAdapter::class);
    }

    /** Register the fixture graph, translation catalog, commands, and worker scope probe. */
    public function boot(
        TenantResourceRegistry $tenantResources,
        TenantAdoptionRegistry $adoptions,
        TranslationResourceRegistry $translations,
    ): void {
        $tenantResources->register(new TenantResourceDefinition('test.entries', 'test', TenantSelfEntry::class));
        $tenantResources->register(new TenantResourceDefinition('test.articles', 'test-articles', TenantArticle::class));
        $tenantResources->register(new TenantResourceDefinition(
            'test.article-translations',
            'test-articles',
            TenantArticleTranslation::class,
            TenantResourceKind::Inherited,
            'test.articles',
            'article',
        ));
        $adoptions->register('translation-fixtures', TenantTranslationFixtureAdoptionAdapter::class);
        $translations->register('test.entries', TenantSelfEntry::class, 'Entries', ['name'], ['name']);
        $translations->register('test.articles', TenantArticle::class, 'Articles', ['slug'], ['slug']);

        $this->commands([TenancyConsumerSetupCommand::class, TenancyConsumerRaceCommand::class]);
        Queue::looping(static function (Looping $event): void {
            if (! Schema::hasTable('tenant_probe_results')) {
                return;
            }
            DB::table('tenant_probe_results')->updateOrInsert(
                ['result_key' => 'worker-scope'],
                [
                    'tenant_id' => app(TenantContext::class)->snapshot()->tenantId?->value,
                    'value' => app(ContentLocale::class)->get(),
                ],
            );
        });
    }
}
