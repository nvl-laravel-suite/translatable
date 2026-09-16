<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceLocator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
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
