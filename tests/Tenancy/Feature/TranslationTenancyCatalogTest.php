<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__, 2).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TenancyTestCase');
}

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceLocator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\DoctorSameNameConnectionArticle;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;

it('does not use B locale rows for A central coverage or search', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A only');
    $s->entry($s::B, 'same', 'bg', 'secret needle');

    $page = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
        'test.entries',
        SystemActor::Background,
        ['search' => 'secret needle'],
    ));

    expect($page->total())->toBe(0);
});

it('partitions central coverage missing locale and loaded rows by tenant', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A only');
    $s->entry($s::B, 'same', 'bg', 'B only');

    [$summary, $missing, $page] = $s->run($s::A, function (): array {
        $gatherer = app(TranslationResourceGatherer::class);
        $summary = collect($gatherer->summaries(SystemActor::Background))
            ->firstWhere('key', 'test.entries');

        return [
            $summary,
            $gatherer->gather(
                'test.entries',
                SystemActor::Background,
                ['missing_locale' => 'bg'],
            ),
            $gatherer->gather('test.entries', SystemActor::Background),
        ];
    });

    expect($summary)
        ->not->toBeNull()
        ->and($summary['total'])->toBe(1)
        ->and($summary['coverage']['en']['translated'])->toBe(1)
        ->and($summary['coverage']['bg']['translated'])->toBe(0)
        ->and($missing->total())->toBe(1)
        ->and($page->total())->toBe(1)
        ->and($page->items()[0]['translations'])->toHaveKey('en')
        ->and($page->items()[0]['translations'])->not->toHaveKey('bg');
});

it('rejects mixed-context batches before preloading translations', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A');
    $b = $s->entry($s::B, 'same', 'bg', 'B');

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$a, $b])),
    ))->toThrow(OwnershipViolation::class);
});

it('includes the ownership partition in central optimistic versions', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'identical');
    $s->entry($s::B, 'same', 'en', 'identical');

    $versionA = $s->run($s::A, fn (): string => app(TranslationResourceGatherer::class)
        ->gather('test.entries', SystemActor::Background)
        ->items()[0]['version']);
    $versionB = $s->run($s::B, fn (): string => app(TranslationResourceGatherer::class)
        ->gather('test.entries', SystemActor::Background)
        ->items()[0]['version']);

    expect($versionA)
        ->not->toBe($versionB)
        ->and(fn () => $s->run($s::A, fn () => app(TranslationWriter::class)->sync(
            TenantSelfEntry::query()->translationGroup('same')->firstOrFail(),
            ['en' => ['name' => 'unauthorized stale write']],
            SystemActor::Background,
            $versionB,
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
        SystemActor::Background,
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['translations']['en']['name'])->toBe('A');
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
        SystemActor::Background,
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['translations']['en']['name'])->toBe('A');
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
        SystemActor::Background,
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['translations']['en']['name'])->toBe('A');
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
        SystemActor::Background,
    ));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['translations']['en']['name'])->toBe('A');
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
        $singleQueries = $connection->getQueryLog();
        $singleCount = count($singleQueries);
        $connection->flushQueryLog();

        $locator->loadTranslations($records);
        $records->each(static fn ($record): string => $versioner->version($record));
        $pageQueries = $connection->getQueryLog();
        $pageCount = count($pageQueries);
        $connection->disableQueryLog();

        expect($pageCount)->toBe($singleCount)
            ->and($pageCount)->toBe(82);
        foreach ([$singleQueries, $pageQueries] as $queries) {
            $sql = array_column($queries, 'query');
            expect(count(array_filter($sql, static fn (string $query): bool => str_contains($query, 'sqlite_master') && str_contains($query, 'nvl_ownership_stores'))))->toBe(20)
                ->and(count(array_filter($sql, static fn (string $query): bool => str_contains($query, 'sqlite_master') && str_contains($query, "type = 'trigger'"))))->toBe(4)
                ->and(count(array_filter($sql, static fn (string $query): bool => str_starts_with($query, 'select * from "tenant_test_entries"'))))->toBe(2);
        }
    });
});

it('rechecks live owner, store, generation, and trigger state on a consecutive preload', function (string $change, string $exception): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A only');

    $s->run($s::A, function () use ($change, $exception): void {
        $resource = app(TranslationResourceRegistry::class)->get('test.entries');
        $locator = app(TranslationResourceLocator::class);
        $records = $locator->query($resource)->get();
        $locator->loadTranslations($records);
        $connection = $records->firstOrFail()->getConnection();

        match ($change) {
            'store' => DB::table('nvl_ownership_stores')->where('id', 1)->update(['store_id' => (string) Str::uuid()]),
            'generation' => DB::table('nvl_ownership_resource_markers')->where('resource', 'test.entries')->update(['generation' => (string) Str::uuid()]),
            'owner' => (static function (): void {
                Schema::disableForeignKeyConstraints();
                DB::table('tenant_test_owners')->where('id', TenantTranslationScenario::A)->delete();
                Schema::enableForeignKeyConstraints();
            })(),
            'trigger' => (static function () use ($connection): void {
                $name = app(OwnershipConstraintInspector::class)->name($connection, 'tenant_test_entries', 'insert', 'valid');
                DB::statement('DROP TRIGGER "'.$name.'"');
            })(),
        };

        $connection->enableQueryLog();
        $connection->flushQueryLog();
        expect(fn () => $locator->loadTranslations($records))->toThrow($exception);
        $queries = array_column($connection->getQueryLog(), 'query');
        $connection->disableQueryLog();
        expect(count(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'select * from "tenant_test_entries"'))))->toBe(0);
    });
})->with([
    'store UUID changed' => ['store', OwnershipSchemaNotReady::class],
    'marker generation changed' => ['generation', OwnershipSchemaNotReady::class],
    'canonical owner deleted' => ['owner', OwnershipViolation::class],
    'required trigger dropped' => ['trigger', OwnershipSchemaNotReady::class],
]);

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

        expect($queryCount)->toBeLessThanOrEqual(150);

        $retained = $records->last();
        if (! $retained instanceof TenantSelfEntry) {
            throw new LogicException('Expected one retained tenant entry.');
        }

        return $retained;
    });

    expect(fn (): string => $s->run(
        $s::B,
        fn (): string => $versioner->version($retained),
    ))->toThrow(OwnershipViolation::class);
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
    ))->toThrow(OwnershipViolation::class);
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
            ->toThrow(OwnershipViolation::class);
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
    ))->toThrow(OwnershipViolation::class);
});

it('rejects forged self ownership partitions before loading locale rows', function (): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'original', 'en', 'Original');
    $entry->setRawAttributes([...$entry->getAttributes(), 'tenant_id' => $s::B], true);

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$entry])),
    ))->toThrow(OwnershipViolation::class);
});

it('rejects forged related owner keys before loading locale rows', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'original', ['en' => ['name' => 'Original']]);
    $other = $s->article($s::A, 'other', ['en' => ['name' => 'Other']]);
    $article->setAttribute('id', $other->getKey());

    expect(fn () => $s->run(
        $s::A,
        fn () => app(TranslationResourceLocator::class)->loadTranslations(new Collection([$article])),
    ))->toThrow(OwnershipViolation::class);
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
        SystemActor::Background,
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
        SystemActor::Background,
        ['search' => 'hidden needle'],
    ));

    expect($page->total())->toBe(0);
});

it('keeps central catalog rows outside an owner-scoped listing', function (): void {
    $s = TenantTranslationScenario::install(mixedEntries: true);
    app(OwnershipRunner::class)->central(
        'translation-maintain',
        OwnershipActor::query()->firstOrFail(),
        static fn () => app(OwnershipWriter::class)->create(
            TenantSelfEntry::class,
            static function (OwnershipAssignment $assignment): TenantSelfEntry {
                $entry = new TenantSelfEntry([
                    'entry_key' => 'platform-entry', 'locale' => 'en', 'name' => 'Central catalog row',
                ]);
                $entry->forceFill($assignment->attributes());
                $entry->save();

                return $entry;
            },
        ),
    );

    $summaries = $s->run(
        $s::A,
        fn (): array => app(TranslationResourceGatherer::class)->summaries(SystemActor::Background),
    );
    $summary = collect($summaries)->firstWhere('key', 'test.entries');

    expect($summary)
        ->not->toBeNull()
        ->and($summary['label'])->toBe('Entries')
        ->and($summary['total'])->toBe(0)
        ->and($s->run($s::A, fn () => app(TranslationResourceGatherer::class)->gather(
            'test.entries',
            SystemActor::Background,
        ))->total())->toBe(0);
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

it('reports the exact native self unique index fix without applying it', function (): void {
    TenantTranslationScenario::install();
    Schema::table('tenant_test_entries', static function (Blueprint $table): void {
        $table->dropUnique('tenant_entries_native_group_locale_unique');
    });

    $report = app(TranslationDoctor::class)->inspect();
    $errors = implode(' ', $report->errors);

    expect($errors)
        ->toContain('tenant_test_entries')
        ->toContain('tenant_test_entries_owner_scope_key_entry_key_locale_unique')
        ->and(Schema::hasIndex(
            'tenant_test_entries',
            ['owner_scope_key', 'entry_key', 'locale'],
            'unique',
        ))->toBeFalse();
});

it('diagnoses incomplete unowned storage without requiring an ownership declaration', function (): void {
    TenantTranslationScenario::install();
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    app(TranslationResourceRegistry::class)->register(
        key: 'test.legacy-models',
        modelClass: TestTranslatableModel::class,
        label: 'Legacy models',
    );

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.legacy-models] is missing translation table')
        ->not->toContain('must declare an ownership resource key');
});

it('diagnoses a missing related child ownership registration', function (): void {
    TenantTranslationScenario::install();
    $ownershipResources = new OwnershipResourceRegistry;
    $ownershipResources->register(new OwnershipResourceDefinition('test.entries', 'test', TenantSelfEntry::class, TenantSelfEntry::ownershipDefinition()));
    $ownershipResources->register(new OwnershipResourceDefinition('test.articles', 'test', TenantArticle::class, TenantArticle::ownershipDefinition()));
    app()->instance(OwnershipResourceRegistry::class, $ownershipResources);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.articles] related translation model')
        ->toContain('is not registered');
});

it('diagnoses a related child registered under the wrong inherited parent', function (): void {
    TenantTranslationScenario::install();
    $ownershipResources = new OwnershipResourceRegistry;
    $ownershipResources->register(new OwnershipResourceDefinition('test.entries', 'test', TenantSelfEntry::class, TenantSelfEntry::ownershipDefinition()));
    $ownershipResources->register(new OwnershipResourceDefinition('test.articles', 'test', TenantArticle::class, TenantArticle::ownershipDefinition()));
    $ownershipResources->register(new OwnershipResourceDefinition(
        'test.article-translations', 'test', TenantArticleTranslation::class,
        TenantArticleTranslation::ownershipDefinition(), parentResource: 'test.entries',
    ));
    app()->instance(OwnershipResourceRegistry::class, $ownershipResources);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.articles] related translation ownership must inherit from [test.articles]');
});

it('diagnoses related storage using a different actual connection with the same name', function (): void {
    TenantTranslationScenario::install();
    $resources = new TranslationResourceRegistry(app(OwnerRegistry::class));
    $resources->register('test.same-name-articles', DoctorSameNameConnectionArticle::class, 'Same-name articles');
    app()->instance(TranslationResourceRegistry::class, $resources);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.same-name-articles] owner and translation models use different actual connections');
});

it('diagnoses an incompatible related child adoption marker', function (): void {
    TenantTranslationScenario::install();
    $connection = (new TenantArticleTranslation)->getConnection();
    $connection->table('nvl_ownership_resource_markers')
        ->where('resource', 'test.article-translations')
        ->update(['configuration_hash' => str_repeat('0', 64)]);

    expect(implode(' ', app(TranslationDoctor::class)->inspect()->errors))
        ->toContain('Resource [test.articles] related translation ownership adoption is incompatible');
});
