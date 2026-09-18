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
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationFixtureAdoptionAdapter;

/** Registers deterministic adapters and resources for copied worker processes. */
final class TenancyConsumerServiceProvider extends ServiceProvider
{
    /** Bind the fixture's host-owned directory, authorization, and maintenance ports. */
    public function register(): void
    {
        $this->app->singleton(TenantDirectory::class, TenantTranslationFixtureDirectory::class);
        $this->app->singleton(PlatformAccess::class, TenantTranslationFixturePlatformAccess::class);
        $this->app->singleton(MaintenanceMode::class, TenantTranslationFixtureMaintenanceMode::class);
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
