<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Installs and seeds real adopted storage through the public tenant lifecycle. */
final class TenantTranslationScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    /** Adopt the three explicit fixture resources before any tenant read. */
    public static function install(): self
    {
        config(['tenancy.enabled' => true]);
        app()->instance(TenantDirectory::class, new class implements TenantDirectory
        {
            /** Resolve only the fixture's two active tenants. */
            public function find(TenantId $id): TenantDescriptor
            {
                if (! in_array($id->value, [TenantTranslationScenario::A, TenantTranslationScenario::B], true)) {
                    throw new TenantNotFound;
                }

                return new TenantDescriptor($id, TenantStatus::Active);
            }
        });
        $resources = app(TenantResourceRegistry::class);
        $resources->register(new TenantResourceDefinition('test.entries', 'test', TenantSelfEntry::class));
        $resources->register(new TenantResourceDefinition('test.articles', 'test', TenantArticle::class));
        $resources->register(new TenantResourceDefinition(
            'test.article-translations', 'test', TenantArticleTranslation::class,
            TenantResourceKind::Inherited, 'test.articles', 'article',
        ));
        app(TenantAdoptionRegistry::class)->register('translation-fixtures', TenantTranslationFixtureAdoptionAdapter::class);
        $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
        $coordinator = app(TenantAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['translation-fixtures'], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 20 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        expect($done)->toBeTrue();
        expect($coordinator->verify($plan)->passed())->toBeTrue();
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();
        app(TranslationResourceRegistry::class)->register('test.entries', TenantSelfEntry::class, 'Entries');
        app(TranslationResourceRegistry::class)->register('test.articles', TenantArticle::class, 'Articles');

        return new self;
    }

    /**
     * Run one operation in a restored tenant context.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $id, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($id), $callback);
    }

    /** Persist a grouped fixture row with server-derived ownership. */
    public function entry(string $tenant, string $group, string $locale, string $name): TenantSelfEntry
    {
        return $this->run($tenant, function () use ($group, $locale, $name): TenantSelfEntry {
            $entry = new TenantSelfEntry(['entry_key' => $group, 'locale' => $locale, 'name' => $name]);
            $entry->forceFill(app(TenantBoundary::class)->attributes('test.entries'));
            $entry->save();

            return $entry;
        });
    }

    /**
     * Seed an existing article independently of the translation mutation runtime.
     *
     * @param  array<string, array{name: string}>  $translations
     */
    public function article(string $tenant, string $slug, array $translations): TenantArticle
    {
        return $this->run($tenant, function () use ($tenant, $slug, $translations): TenantArticle {
            $article = new TenantArticle(['slug' => $slug]);

            return $article->getConnection()->transaction(function () use ($tenant, $article, $translations): TenantArticle {
                $article->forceFill(app(TenantBoundary::class)->attributes('test.articles'));
                $article->save();
                foreach ($translations as $locale => $attributes) {
                    TenantArticleTranslation::forceCreate(['tenant_id' => $tenant, 'article_id' => $article->getKey(), 'locale' => $locale, 'name' => $attributes['name']]);
                }

                return $article;
            });
        });
    }
}
