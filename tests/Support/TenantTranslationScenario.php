<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Installs real native owner storage and admits each fixture operation through Ownership. */
final class TenantTranslationScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    private function __construct(private readonly ?string $connection = null) {}

    public static function install(bool $mixedEntries = false, ?string $connection = null, array $adoptionPackages = []): self
    {
        if ($connection !== null) {
            app('db')->setDefaultConnection($connection);
            $schema = app('db')->connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('tenant_test_owners')) {
                $schema->create('tenant_test_owners', static fn (Blueprint $table) => $table->uuid('id')->primary());
                app('db')->connection($connection)->table('tenant_test_owners')->insert([['id' => self::A], ['id' => self::B]]);
            }
            if (! $schema->hasTable('translation_ownership_actors')) {
                $schema->create('translation_ownership_actors', static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
                app('db')->connection($connection)->table('translation_ownership_actors')->insert(['id' => 1]);
            }
            if (! $schema->hasTable('nvl_ownership_stores')) {
                (require __DIR__.'/../../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
            }
        }
        $resources = app(OwnershipResourceRegistry::class);
        $resources->register(new OwnershipResourceDefinition(
            'test.entries', 'test', TenantSelfEntry::class, TenantSelfEntry::ownershipDefinition(),
            allowsCentral: $mixedEntries, immutableFields: ['entry_key', 'locale'],
        ));
        $resources->register(new OwnershipResourceDefinition(
            'test.articles', 'test', TenantArticle::class, TenantArticle::ownershipDefinition(),
        ));
        $resources->register(new OwnershipResourceDefinition(
            'test.article-translations', 'test', TenantArticleTranslation::class,
            TenantArticleTranslation::ownershipDefinition(), parentResource: 'test.articles',
        ));
        app()->instance(TenantTranslationFixtureAdoptionAdapter::class, new TenantTranslationFixtureAdoptionAdapter(app(OwnershipSchema::class), $mixedEntries));
        app(OwnershipAdoptionRegistry::class)->register('translation-fixtures', TenantTranslationFixtureAdoptionAdapter::class);
        $operation = new OwnershipOperation('fixture.adoption', 'test', 'fixture');
        $coordinator = app(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['translation-fixtures', ...$adoptionPackages], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 20 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        expect($done)->toBeTrue();
        expect($coordinator->verify($plan)->passed())->toBeTrue();
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();
        app(TranslationResourceRegistry::class)->register(
            key: 'test.entries', modelClass: TenantSelfEntry::class, label: 'Entries',
            searchableColumns: ['name'], displayColumns: ['name'],
        );
        app(TranslationResourceRegistry::class)->register(
            key: 'test.articles', modelClass: TenantArticle::class, label: 'Articles',
            searchableColumns: ['slug'], displayColumns: ['slug'],
        );

        return new self($connection);
    }

    /** @template T @param Closure(): T $callback @return T */
    public function run(string $id, Closure $callback): mixed
    {
        return app(OwnershipRunner::class)->run(TenantTranslationOwner::findOrFail($id), OwnershipActor::firstOrFail(), $callback);
    }

    public function entry(string $tenant, string $group, string $locale, string $name): TenantSelfEntry
    {
        return $this->run($tenant, function () use ($group, $locale, $name): TenantSelfEntry {
            return app(OwnershipWriter::class)->create(TenantSelfEntry::class, function (OwnershipAssignment $assignment) use ($group, $locale, $name): TenantSelfEntry {
                $entry = new TenantSelfEntry(['entry_key' => $group, 'locale' => $locale, 'name' => $name]);
                $entry->setConnection($this->connection);
                $entry->forceFill($assignment->attributes());
                $entry->save();

                return $entry;
            });
        });
    }

    /** @param array<string, array{name: string}> $translations */
    public function article(string $tenant, string $slug, array $translations): TenantArticle
    {
        return $this->run($tenant, function () use ($slug, $translations): TenantArticle {
            $article = app(OwnershipWriter::class)->create(TenantArticle::class, function (OwnershipAssignment $assignment) use ($slug): TenantArticle {
                $record = new TenantArticle(['slug' => $slug]);
                $record->setConnection($this->connection);
                $record->forceFill($assignment->attributes());
                $record->save();

                return $record;
            });

            return $article->getConnection()->transaction(function () use ($article, $translations): TenantArticle {
                foreach ($translations as $locale => $attributes) {
                    app(OwnershipWriter::class)->create(TenantArticleTranslation::class, function (OwnershipAssignment $assignment) use ($article, $locale, $attributes): TenantArticleTranslation {
                        $translation = new TenantArticleTranslation;
                        $translation->setConnection($this->connection);
                        $translation->forceFill([
                            ...$assignment->attributes(), 'tenant_id' => $article->tenant_id,
                            'article_id' => $article->getKey(), 'locale' => $locale,
                            'name' => $attributes['name'],
                        ]);
                        $translation->save();

                        return $translation;
                    });
                }

                return $article;
            });
        });
    }
}
