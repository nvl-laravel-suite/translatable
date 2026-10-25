<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationFixtureAdoptionAdapter;
use Nvl\Translatable\TranslationResourceDefinition;

/** Registers native owner policy and concrete resources in copied worker processes. */
final class TenancyConsumerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MaintenanceMode::class, TenantTranslationFixtureMaintenanceMode::class);
        $this->app->singleton(TenantTranslationFixtureAdoptionAdapter::class);
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
        $this->app->bind(TranslationResourceAuthorizer::class, static fn () => new class implements TranslationResourceAuthorizer
        {
            public function allows(
                Authenticatable|SystemActor $actor,
                TranslationResourceAbility $ability,
                TranslationResourceDefinition $resource,
                ?Model $record = null,
            ): bool {
                return $actor instanceof OwnershipActor;
            }
        });
    }

    public function boot(
        OwnershipResourceRegistry $ownershipResources,
        OwnershipAdoptionRegistry $adoptions,
        TranslationResourceRegistry $translations,
    ): void {
        $ownershipResources->register(new OwnershipResourceDefinition(
            'test.entries', 'test', TenantSelfEntry::class, TenantSelfEntry::ownershipDefinition(),
            immutableFields: ['entry_key', 'locale'],
        ));
        $ownershipResources->register(new OwnershipResourceDefinition(
            'test.articles', 'test', TenantArticle::class, TenantArticle::ownershipDefinition(),
        ));
        $ownershipResources->register(new OwnershipResourceDefinition(
            'test.article-translations', 'test', TenantArticleTranslation::class,
            TenantArticleTranslation::ownershipDefinition(), parentResource: 'test.articles',
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
                    'tenant_id' => app(OwnershipContext::class)->snapshot()->owner?->key,
                    'value' => app(ContentLocale::class)->get(),
                ],
            );
        });
    }
}
