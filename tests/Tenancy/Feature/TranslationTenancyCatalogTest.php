<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceLocator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Tests\Support\DoctorSameNameConnectionArticle;
use Nvl\Translatable\Tests\Support\DoctorSameNameConnectionArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\TranslationResourceQuery;

it('does not use B locale rows for A central coverage or search', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A only');
    $s->entry($s::B, 'same', 'bg', 'secret needle');

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.entries',
        TranslationActorData::system(),
        new TranslationResourceQuery(search: 'secret needle'),
    ));

    expect($page->total())->toBe(0);
});

it('partitions central coverage missing locale and loaded rows by tenant', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A only');
    $s->entry($s::B, 'same', 'bg', 'B only');

    [$summary, $missing, $page] = $s->run($s::A, function (): array {
        $gatherer = app(TranslationResourceGatherer::class);
        $summary = collect($gatherer->summaries(TranslationActorData::system()))
            ->firstWhere('key', 'test.entries');

        return [
            $summary,
            $gatherer->gather(
                'test.entries',
                TranslationActorData::system(),
                new TranslationResourceQuery(missingLocale: 'bg'),
            ),
            $gatherer->gather('test.entries', TranslationActorData::system()),
        ];
    });

    expect($summary)
        ->not->toBeNull()
        ->and($summary->total)->toBe(1)
        ->and($summary->coverage['en']->translated)->toBe(1)
        ->and($summary->coverage['bg']->translated)->toBe(0)
        ->and($missing->total())->toBe(1)
        ->and($page->total())->toBe(1)
        ->and($page->items()[0]->translations)->toHaveKey('en')
        ->and($page->items()[0]->translations)->not->toHaveKey('bg');
});

it('rejects mixed-context batches before preloading translations', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A');
    $b = $s->entry($s::B, 'same', 'bg', 'B');

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$a, $b])),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('includes the ownership partition in central optimistic versions', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'identical');
    $s->entry($s::B, 'same', 'en', 'identical');

    $versionA = $s->run($s::A, fn (): string => app(TranslationResourceGatherer::class)
        ->gather('test.entries', TranslationActorData::system())
        ->items()[0]
        ->version);
    $versionB = $s->run($s::B, fn (): string => app(TranslationResourceGatherer::class)
        ->gather('test.entries', TranslationActorData::system())
        ->items()[0]
        ->version);

    expect($versionA)
        ->not->toBe($versionB)
        ->and(fn () => $s->run($s::A, fn () => app(SyncTranslationResourceAction::class)->execute(
            'test.entries',
            'same',
            new TranslationMutationData(
                translations: ['en' => ['name' => 'unauthorized stale write']],
                expectedVersion: $versionB,
            ),
            TranslationActorData::system(),
        )))->toThrow(TranslationResourceException::class, 'changed after it was read');
});

it('reapplies ownership after a configured scope returns a wider builder', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'a', 'en', 'A');
    $s->entry($s::B, 'b', 'en', 'B');
    app(TranslationResourceRegistry::class)->register(
        key: 'test.wide-entries',
        modelClass: TenantSelfEntry::class,
        label: 'Wide entries',
        displayColumns: ['name'],
        queryScope: static fn (Builder $query): Builder => TenantSelfEntry::query(),
    );

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.wide-entries',
        TranslationActorData::system(),
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->translations['en']['name'])->toBe('A');
});

it('reapplies ownership after a configured scope widens its builder in place', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'a', 'en', 'A');
    $s->entry($s::B, 'b', 'en', 'B');
    app(TranslationResourceRegistry::class)->register(
        key: 'test.in-place-wide-entries',
        modelClass: TenantSelfEntry::class,
        label: 'In-place wide entries',
        displayColumns: ['name'],
        queryScope: static function (Builder $query): Builder {
            $query->getQuery()->wheres = [];
            $query->getQuery()->setBindings([], 'where');

            return $query;
        },
    );

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.in-place-wide-entries',
        TranslationActorData::system(),
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->translations['en']['name'])->toBe('A');
});

it('keeps ownership after a configured scope registers a late base query replacement', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'a', 'en', 'A');
    $s->entry($s::B, 'b', 'en', 'B');
    app(TranslationResourceRegistry::class)->register(
        key: 'test.late-base-entries',
        modelClass: TenantSelfEntry::class,
        label: 'Late base entries',
        displayColumns: ['name'],
        queryScope: static function (Builder $query): Builder {
            $query->getQuery()->beforeQuery(static function (QueryBuilder $query): void {
                $query->wheres = [];
                $query->setBindings([], 'where');
            });

            return $query;
        },
    );

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.late-base-entries',
        TranslationActorData::system(),
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->translations['en']['name'])->toBe('A');
});

it('keeps ownership after a configured scope registers a late global scope replacement', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'a', 'en', 'A');
    $s->entry($s::B, 'b', 'en', 'B');
    app(TranslationResourceRegistry::class)->register(
        key: 'test.late-global-entries',
        modelClass: TenantSelfEntry::class,
        label: 'Late global entries',
        displayColumns: ['name'],
        queryScope: static fn (Builder $query): Builder => $query->withGlobalScope(
            'late-replacement',
            static function (Builder $query): void {
                $query->getQuery()->wheres = [];
                $query->getQuery()->setBindings([], 'where');
            },
        ),
    );

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.late-global-entries',
        TranslationActorData::system(),
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->translations['en']['name'])->toBe('A');
});

it('keeps central preload and version query counts constant as a page grows', function (): void {
    $s = TenantTranslationScenario::install();
    foreach (range(1, 8) as $index) {
        $s->entry($s::A, 'entry-'.$index, 'en', 'Entry '.$index);
    }

    $s->run($s::A, function (): void {
        $resource = app(TranslationResourceRegistry::class)->get('test.entries');
        $locator = app(TranslationResourceLocator::class);
        $versioner = app(TranslationResourceVersioner::class);
        $records = $locator->query($resource)->get();
        $connection = $records->firstOrFail()->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $single = new Collection([$records->firstOrFail()]);
        $locator->loadTranslations($single);
        $single->each(static fn ($record): string => $versioner->version($record));
        $singleCount = count($connection->getQueryLog());
        $connection->flushQueryLog();

        $locator->loadTranslations($records);
        $records->each(static fn ($record): string => $versioner->version($record));
        $pageCount = count($connection->getQueryLog());
        $connection->disableQueryLog();

        expect($pageCount)->toBe($singleCount)
            ->and($pageCount)->toBeLessThanOrEqual(2);
    });
});

it('rejects a retained preloaded version after the tenant context changes', function (): void {
    $s = TenantTranslationScenario::install();
    foreach (range(1, 8) as $index) {
        $s->entry($s::A, 'entry-'.$index, 'en', 'Tenant A '.$index);
    }
    $s->entry($s::B, 'entry-8', 'en', 'Tenant B');
    $locator = app(TranslationResourceLocator::class);
    $versioner = app(TranslationResourceVersioner::class);

    $retained = $s->run($s::A, function () use ($locator, $versioner): TenantSelfEntry {
        $resource = app(TranslationResourceRegistry::class)->get('test.entries');
        $records = $locator->query($resource)->get();
        $connection = $records->firstOrFail()->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $locator->loadTranslations($records);
        $records->take(7)->each(static fn ($record): string => $versioner->version($record));
        $queryCount = count($connection->getQueryLog());
        $connection->disableQueryLog();

        expect($queryCount)->toBeLessThanOrEqual(2);

        $retained = $records->last();
        if (! $retained instanceof TenantSelfEntry) {
            throw new LogicException('Expected one retained tenant entry.');
        }

        return $retained;
    });

    expect(fn (): string => $s->run(
        $s::B,
        fn (): string => $versioner->version($retained),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('rejects a retained preloaded version in a later operation for the same tenant', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'Tenant A');
    $foreign = $s->entry($s::B, 'same', 'en', 'Tenant B');
    $locator = app(TranslationResourceLocator::class);
    $versioner = app(TranslationResourceVersioner::class);

    $retained = $s->run($s::A, function () use ($locator): TenantSelfEntry {
        $resource = app(TranslationResourceRegistry::class)->get('test.entries');
        $record = $locator->query($resource)->firstOrFail();
        $locator->loadTranslations(new Collection([$record]));

        return $record;
    });
    $retained->setRelation('translations', new Collection([$foreign]));

    expect(fn (): string => $s->run(
        $s::A,
        fn (): string => $versioner->version($retained),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('clears every prior preload identity before a partial batch failure', function (): void {
    $s = TenantTranslationScenario::install();
    $first = $s->entry($s::A, 'first', 'en', 'First');
    $last = $s->entry($s::A, 'last', 'en', 'Last');
    $invalid = $s->article($s::A, 'article', ['en' => ['name' => 'Article']]);
    $locator = app(TranslationResourceLocator::class);
    $versioner = app(TranslationResourceVersioner::class);

    $s->run($s::A, function () use ($first, $invalid, $last, $locator, $versioner): void {
        $locator->loadTranslations(new Collection([$first, $last]));
        $last->setAttribute('tenant_id', TenantTranslationScenario::B);

        expect(fn () => $locator->loadTranslations(new Collection([$first, $invalid, $last])))
            ->toThrow(TranslationResourceException::class);
        expect(fn (): string => $versioner->version($last))
            ->toThrow(TenantBoundaryViolation::class);
    });
});

it('rejects stale self group identities before loading locale rows', function (): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'original', 'en', 'Original');
    $s->entry($s::A, 'other', 'en', 'Other');
    $entry->setRawAttributes([...$entry->getAttributes(), 'entry_key' => 'other'], true);

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$entry])),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('rejects forged self ownership partitions before loading locale rows', function (): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'original', 'en', 'Original');
    $entry->setRawAttributes([...$entry->getAttributes(), 'tenant_id' => $s::B], true);

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$entry])),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('rejects forged related owner keys before loading locale rows', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'original', ['en' => ['name' => 'Original']]);
    $other = $s->article($s::A, 'other', ['en' => ['name' => 'Other']]);
    $article->setAttribute('id', $other->getKey());

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$article])),
    ))->toThrow(TenantBoundaryViolation::class);
});

it('loads related translations after an unrelated owner field changes', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'original', ['en' => ['name' => 'Original']]);

    $loaded = $s->run($s::A, function () use ($article): TenantArticle {
        $resource = app(TranslationResourceRegistry::class)->get('test.articles');
        $selected = app(TranslationResourceLocator::class)->query($resource)->findOrFail($article->getKey());
        $selected->getConnection()->table($selected->getTable())
            ->where($selected->getKeyName(), $selected->getKey())
            ->update(['slug' => 'changed-after-selection']);

        app(TranslationResourceLocator::class)->loadTranslations(new Collection([$selected]));

        return $selected;
    });

    expect($loaded->getRelation('translations'))
        ->toHaveCount(1)
        ->and($loaded->getRelation('translations')->firstOrFail()->getAttribute('name'))
        ->toBe('Original');
});

it('rejects configured scopes that mutate canonical SQL storage in place', function (): void {
    $s = TenantTranslationScenario::install();
    app(TranslationResourceRegistry::class)->register(
        key: 'test.mutated-entries',
        modelClass: TenantSelfEntry::class,
        label: 'Mutated entries',
        queryScope: static fn (Builder $query): Builder => $query->from('tenant_test_articles'),
    );

    expect(fn () => $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.mutated-entries',
        TranslationActorData::system(),
    )))->toThrow(TranslationResourceException::class, 'registered model, table, and connection');
});

it('preserves configured visibility for self-row search candidates', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'visible');
    $s->entry($s::A, 'same', 'bg', 'hidden needle');
    app(TranslationResourceRegistry::class)->register(
        key: 'test.visible-entries',
        modelClass: TenantSelfEntry::class,
        label: 'Visible entries',
        searchableColumns: ['name'],
        queryScope: static fn (Builder $query): Builder => $query->where('locale', 'en'),
    );

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.visible-entries',
        TranslationActorData::system(),
        new TranslationResourceQuery(search: 'hidden needle'),
    ));

    expect($page->total())->toBe(0);
});

it('reports platform catalog metadata without scanning it from tenant context', function (): void {
    config()->set('tenancy.resources.test', 'platform');
    $s = TenantTranslationScenario::install(mixedEntries: true);
    app(TenantRunner::class)->platform(
        new PlatformOperation('fixture.adoption', 'test', 'fixture'),
        function (): void {
            $entry = new TenantSelfEntry([
                'entry_key' => 'platform-entry',
                'locale' => 'en',
                'name' => 'Platform catalog row',
            ]);
            $entry->forceFill(app(TenantBoundary::class)->attributes('test.entries'));
            $entry->save();
        },
    );

    $summaries = $s->run(
        $s::A,
        fn (): array => app(TranslationResourceGatherer::class)->summaries(TranslationActorData::system()),
    );
    $summary = collect($summaries)->firstWhere('key', 'test.entries');

    expect($summary)
        ->not->toBeNull()
        ->and($summary->label)->toBe('Entries')
        ->and($summary->total)->toBe(0)
        ->and(fn () => $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
            'test.entries',
            TranslationActorData::system(),
        )))->toThrow(TenantBoundaryViolation::class);
});

it('diagnoses the adopted tenant schema without mutating it', function (): void {
    TenantTranslationScenario::install();
    $before = [
        Schema::getIndexes('tenant_test_entries'),
        Schema::getIndexes('tenant_test_article_translations'),
        Schema::getForeignKeys('tenant_test_article_translations'),
    ];

    $report = app(TranslationDoctor::class)->inspect();

    expect(implode(' ', $report->errors))
        ->not->toContain('Resource [test.entries]')
        ->not->toContain('Resource [test.articles]')
        ->and([
            Schema::getIndexes('tenant_test_entries'),
            Schema::getIndexes('tenant_test_article_translations'),
            Schema::getForeignKeys('tenant_test_article_translations'),
        ])->toBe($before);
});

it('reports the exact tenant self unique index fix without applying it', function (): void {
    TenantTranslationScenario::install();
    Schema::table('tenant_test_entries', static function (Blueprint $table): void {
        $table->dropUnique('tenant_entries_group_locale_unique');
    });

    $report = app(TranslationDoctor::class)->inspect();
    $errors = implode(' ', $report->errors);

    expect($errors)
        ->toContain('tenant_test_entries')
        ->toContain('tenant_test_entries_tenant_id_entry_key_locale_unique')
        ->and(Schema::hasIndex(
            'tenant_test_entries',
            ['tenant_id', 'entry_key', 'locale'],
            'unique',
        ))->toBeFalse();
});

it('diagnoses undeclared legacy ownership after tenancy is enabled', function (): void {
    TenantTranslationScenario::install();
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    app(TranslationResourceRegistry::class)->register(
        key: 'test.legacy-models',
        modelClass: TestTranslatableModel::class,
        label: 'Legacy models',
    );

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.legacy-models] must declare an ownership resource key');
});

it('diagnoses a missing related child ownership registration', function (): void {
    TenantTranslationScenario::install();
    $tenantResources = new TenantResourceRegistry;
    $tenantResources->register(new TenantResourceDefinition('test.entries', 'test', TenantSelfEntry::class));
    $tenantResources->register(new TenantResourceDefinition('test.articles', 'test-articles', TenantArticle::class));
    app()->instance(TenantResourceRegistry::class, $tenantResources);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.articles] related translation model')
        ->toContain('is not registered');
});

it('diagnoses a related child registered under the wrong inherited parent', function (): void {
    TenantTranslationScenario::install();
    $tenantResources = new TenantResourceRegistry;
    $tenantResources->register(new TenantResourceDefinition('test.entries', 'test', TenantSelfEntry::class));
    $tenantResources->register(new TenantResourceDefinition('test.articles', 'test-articles', TenantArticle::class));
    $tenantResources->register(new TenantResourceDefinition(
        'test.article-translations',
        'test-articles',
        TenantArticleTranslation::class,
        TenantResourceKind::Inherited,
        'test.entries',
        'article',
    ));
    app()->instance(TenantResourceRegistry::class, $tenantResources);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.articles] related translation ownership must inherit from [test.articles]');
});

it('diagnoses related storage using a different actual connection with the same name', function (): void {
    TenantTranslationScenario::install();
    $resources = new TranslationResourceRegistry;
    $resources->register('test.same-name-articles', DoctorSameNameConnectionArticle::class, 'Same-name articles');
    app()->instance(TranslationResourceRegistry::class, $resources);
    $tenantResources = new TenantResourceRegistry;
    $tenantResources->register(new TenantResourceDefinition(
        'test.articles',
        'test-articles',
        DoctorSameNameConnectionArticle::class,
    ));
    $tenantResources->register(new TenantResourceDefinition(
        'test.article-translations',
        'test-articles',
        DoctorSameNameConnectionArticleTranslation::class,
        TenantResourceKind::Inherited,
        'test.articles',
        'article',
    ));
    app()->instance(TenantResourceRegistry::class, $tenantResources);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.same-name-articles] owner and translation models use different actual connections');
});

it('diagnoses an incompatible related child adoption marker', function (): void {
    TenantTranslationScenario::install();
    $connection = (new TenantArticleTranslation)->getConnection();
    $connection->table('nvl_tenancy_installation_state')
        ->where('resource', 'test.article-translations')
        ->update(['configuration_hash' => str_repeat('0', 64)]);
    app(TenantInstallationState::class)->invalidate();

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.articles] related translation ownership adoption is incompatible');
});
