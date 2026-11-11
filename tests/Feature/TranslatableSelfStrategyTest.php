<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Translatable\Enums\TranslationStorageStrategy;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Events\TranslationResourceLocaleDeleted;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\SelfTranslationStore;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestAllowEmptySelfTranslatableModel;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;

beforeEach(function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('nvl-translatable.fallback_locales', ['en']);

    Schema::dropIfExists('test_self_translatable_models');
    Schema::create('test_self_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->string('entry_key');
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->nullable();
        $table->unique(['entry_key', 'locale'], 'self_translation_unique');
    });

    app(TranslationResourceRegistry::class)->register(
        key: 'tests.self-models',
        modelClass: TestSelfTranslatableModel::class,
        label: 'Self-translated models',
        searchableColumns: ['entry_key', 'name'],
        displayColumns: ['entry_key'],
        orderColumn: 'entry_key',
    );
});

test('self translated comparisons preserve explicit null and literal operators', function (): void {
    TestSelfTranslatableModel::create(['entry_key' => 'null', 'locale' => 'en', 'name' => null]);
    TestSelfTranslatableModel::create(['entry_key' => 'equals', 'locale' => 'en', 'name' => '=']);

    expect(TestSelfTranslatableModel::whereTranslated('name', '=', null, 'en')->pluck('entry_key')->all())->toBe(['null'])
        ->and(TestSelfTranslatableModel::whereTranslated('name', '!=', null, 'en')->pluck('entry_key')->all())->toBe(['equals'])
        ->and(TestSelfTranslatableModel::whereTranslated('name', null, locale: 'en')->pluck('entry_key')->all())->toBe(['null'])
        ->and(TestSelfTranslatableModel::whereTranslated('name', '=', locale: 'en')->pluck('entry_key')->all())->toBe(['equals']);
});

test('self locale batches keep owner and whole-group locks independent of locale count', function (): void {
    $counts = [];
    foreach ([['en'], ['en', 'bg', 'en-GB']] as $index => $locales) {
        $owner = TestSelfTranslatableModel::create(['entry_key' => 'batch-'.$index, 'locale' => 'en', 'name' => 'Original']);
        $version = app(TranslationResourceVersioner::class)->version($owner);
        $connection = DB::connection();
        $localeComparison = $connection->getQueryGrammar()->wrap('locale').' =';
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            app(TranslationWriter::class)->sync($owner, array_fill_keys($locales, ['name' => 'Batch']), SystemActor::Background, $version);
            $counts[] = count(array_filter($connection->getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                && str_contains($query['query'], 'test_self_translatable_models')
                && ! str_contains($query['query'], $localeComparison)));
        } finally {
            $connection->disableQueryLog();
        }
        expect(TestSelfTranslatableModel::where('entry_key', 'batch-'.$index)->count())->toBe(count($locales));
    }

    expect($counts[1])->toBe($counts[0]);
});

test('self locale batches require the actual group transaction before writing', function (): void {
    $owner = TestSelfTranslatableModel::create(['entry_key' => 'batch-transaction', 'locale' => 'en', 'name' => 'Original']);

    expect(fn () => app(SelfTranslationStore::class)->upsertMany($owner, $owner->translationDefinition(), ['bg' => ['name' => 'Rejected']]))
        ->toThrow(OwnershipViolation::class)
        ->and(TestSelfTranslatableModel::where('entry_key', 'batch-transaction')->count())->toBe(1);
});

test('it declares and writes grouped translations without a canonical owner row', function (): void {
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.welcome',
        'locale' => 'en',
        'name' => 'Welcome',
        'description' => 'English description',
        'type' => 'system',
    ]);
    $writer = app(TranslationWriter::class);
    $versioner = app(TranslationResourceVersioner::class);
    $initialVersion = $versioner->version($model);

    $bulgarian = $writer->sync($model, ['bg' => [
        'name' => 'Добре дошли',
        'description' => 'Българско описание',
    ]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $translatedVersion = $versioner->version($model);
    $model->forceFill(['type' => 'custom'])->save();

    expect($model->translationDefinition()->storage())->toBe(TranslationStorageStrategy::Self)
        ->and($model->translationResourceKey())->toBe('catalog.welcome')
        ->and($model->translated('name', 'bg'))->toBe('Добре дошли')
        ->and($model->getAvailableLocales())->toBe(['bg', 'en'])
        ->and($bulgarian->getAttribute('type'))->toBe('system')
        ->and($translatedVersion)->not->toBe($initialVersion)
        ->and($versioner->version($model))->not->toBe($translatedVersion);
});

test('it selects one deterministic requested or fallback row per logical group', function (): void {
    TestSelfTranslatableModel::create([
        'entry_key' => 'visible.complete',
        'locale' => 'en',
        'name' => 'Complete',
        'type' => 'visible',
    ]);
    TestSelfTranslatableModel::create([
        'entry_key' => 'visible.complete',
        'locale' => 'bg',
        'name' => 'Завършен',
        'type' => 'visible',
    ]);
    TestSelfTranslatableModel::create([
        'entry_key' => 'visible.fallback',
        'locale' => 'en',
        'name' => 'Fallback',
        'type' => 'visible',
    ]);
    TestSelfTranslatableModel::create([
        'entry_key' => 'hidden.fallback',
        'locale' => 'en',
        'name' => 'Hidden',
        'type' => 'hidden',
    ]);

    $rows = TestSelfTranslatableModel::query()
        ->where('type', 'visible')
        ->locale('bg')
        ->orderBy('entry_key')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('entry_key')->all())->toBe([
            'visible.complete',
            'visible.fallback',
        ])
        ->and($rows->pluck('locale')->all())->toBe(['bg', 'en']);
});

test('it validates fields and preserves the final grouped locale row', function (): void {
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.protected',
        'locale' => 'en',
        'name' => 'Protected',
        'type' => 'system',
    ]);
    $writer = app(TranslationWriter::class);

    expect(fn () => $writer->sync($model, ['bg' => ['entry_key' => 'changed']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))
        ->toThrow(InvalidTranslatableFieldException::class)
        ->and(fn () => $writer->deleteLocale($model, 'en', SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))
        ->toThrow(TranslatableException::class, 'final locale row');

    $writer->sync($model, ['bg' => ['name' => 'Защитен']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $writer->sync($model, ['bg' => ['name' => 'Само български']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model), TranslationSyncMode::Replace);

    expect(
        TestSelfTranslatableModel::query()
            ->where('entry_key', 'catalog.protected')
            ->pluck('locale')
            ->all(),
    )->toBe(['bg']);
});

test('an opted-in self group can become empty through native mutation', function (string $mutation): void {
    app(TranslationResourceRegistry::class)->register(
        'tests.allow-empty-self', TestAllowEmptySelfTranslatableModel::class, 'Allow empty self',
    );
    Event::fake([TranslationResourceSynced::class, TranslationResourceLocaleDeleted::class]);
    $model = TestAllowEmptySelfTranslatableModel::create([
        'entry_key' => 'catalog.removable', 'locale' => 'en', 'name' => 'Only row',
    ]);
    $version = app(TranslationResourceVersioner::class)->version($model);
    $emptyVersion = app(TranslationResourceVersioner::class)->emptySelfGroupVersion($model);
    $writer = app(TranslationWriter::class);

    if ($mutation === 'delete') {
        expect($writer->deleteLocale($model, 'en', SystemActor::Background, $version))->toBeTrue();
        Event::assertDispatched(TranslationResourceLocaleDeleted::class, static fn (TranslationResourceLocaleDeleted $event): bool => $event->ownerId === 'catalog.removable' && $event->previousVersion === $version
            && $event->version === $emptyVersion);
    } else {
        $returned = $writer->sync($model, [], SystemActor::Background, $version, TranslationSyncMode::Replace);
        expect($returned->translationResourceKey())->toBe('catalog.removable')
            ->and($returned->exists)->toBeFalse()
            ->and($returned->getRelation('translations'))->toBeEmpty();
        Event::assertDispatched(TranslationResourceSynced::class, static fn (TranslationResourceSynced $event): bool => $event->ownerId === 'catalog.removable' && $event->previousVersion === $version
            && $event->locales === [] && $event->version === $emptyVersion);
    }

    expect(TestAllowEmptySelfTranslatableModel::query()->count())->toBe(0);
})->with(['delete final locale' => ['delete'], 'replace with empty map' => ['replace']]);

test('self-row service mutations preserve identity and loaded group state', function (): void {
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.convenience',
        'locale' => 'en',
        'name' => 'English',
        'type' => 'system',
    ]);
    $model->setRelation('translations', $model->getAllTranslations());

    $writer = app(TranslationWriter::class);
    $versioner = app(TranslationResourceVersioner::class);
    $writer->sync($model, ['bg' => ['name' => 'Български']], SystemActor::Background, $versioner->version($model));
    $writer->sync($model, ['en-GB' => ['name' => $model->translated('name', 'en')]], SystemActor::Background, $versioner->version($model));

    expect($model->getAllTranslations())->toHaveCount(3)
        ->and($model->translated('name', 'bg'))->toBe('Български')
        ->and($model->translated('name', 'en-GB'))->toBe('English')
        ->and($writer->deleteLocale($model, 'en-GB', SystemActor::Background, $versioner->version($model)))->toBeTrue()
        ->and($writer->deleteLocale($model, 'en-GB', SystemActor::Background, $versioner->version($model)))->toBeFalse()
        ->and($writer->deleteLocale($model, 'bg', SystemActor::Background, $versioner->version($model)))->toBeTrue()
        ->and($writer->deleteLocale($model, 'bg', SystemActor::Background, $versioner->version($model)))->toBeFalse()
        ->and(fn () => $writer->deleteLocale($model, 'en', SystemActor::Background, $versioner->version($model)))
        ->toThrow(TranslatableException::class, 'final locale row')
        ->and(fn () => $writer->sync(new TestSelfTranslatableModel([
            'entry_key' => 'catalog.unsaved',
        ]), ['en' => ['name' => 'Unsaved']], SystemActor::Background, 'unread'))
        ->toThrow(TranslatableException::class, 'persisted owner');

    $model->setAttribute('entry_key', 'catalog.changed');

    expect(fn () => $model->save())
        ->toThrow(TranslatableException::class, 'immutable after creation');
});

test('self-row structural invariants survive muted model events', function (): void {
    $quietlyCreated = new TestSelfTranslatableModel([
        'entry_key' => 'catalog.quiet',
        'name' => 'Quiet',
        'type' => 'system',
    ]);
    $quietlyCreated->saveQuietly();

    expect($quietlyCreated->locale)->toBe('en')
        ->and(fn () => (new TestSelfTranslatableModel([
            'locale' => 'en',
            'name' => 'Missing group',
        ]))->saveQuietly())
        ->toThrow(TranslatableException::class, 'require group column');

    $quietlyCreated->forceFill(['entry_key' => 'catalog.changed']);

    expect(fn () => $quietlyCreated->saveQuietly())
        ->toThrow(TranslatableException::class, 'immutable after creation')
        ->and(
            TestSelfTranslatableModel::query()
                ->whereKey($quietlyCreated->getKey())
                ->value('entry_key'),
        )->toBe('catalog.quiet');
});

test('self-row structural invariants survive later model event mutations', function (): void {
    $existing = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.event-update',
        'locale' => 'en',
        'name' => 'Before',
        'type' => 'system',
    ]);

    TestSelfTranslatableModel::creating(
        static fn (TestSelfTranslatableModel $model) => $model->forceFill(['entry_key' => '']),
    );
    TestSelfTranslatableModel::updating(
        static fn (TestSelfTranslatableModel $model) => $model->forceFill([
            'entry_key' => 'catalog.event-mutated',
        ]),
    );

    try {
        expect(fn () => (new TestSelfTranslatableModel([
            'entry_key' => 'catalog.event-create',
            'locale' => 'en',
            'name' => 'Invalidated by listener',
            'type' => 'system',
        ]))->save())
            ->toThrow(TranslatableException::class, 'require group column');

        $existing->forceFill(['name' => 'After']);

        expect(fn () => $existing->save())
            ->toThrow(TranslatableException::class, 'immutable after creation')
            ->and(
                TestSelfTranslatableModel::query()
                    ->whereKey($existing->getKey())
                    ->value('entry_key'),
            )->toBe('catalog.event-update');
    } finally {
        TestSelfTranslatableModel::flushEventListeners();
        TestSelfTranslatableModel::clearBootedModels();
    }
});

test('it gathers searches and reports logical self-translated resources', function (): void {
    $first = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.first',
        'locale' => 'en',
        'name' => 'First',
        'type' => 'system',
    ]);
    app(TranslationWriter::class)->sync($first, ['bg' => ['name' => 'Първи']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($first));
    TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.second',
        'locale' => 'en',
        'name' => 'Second searchable',
        'type' => 'system',
    ]);
    $gatherer = app(TranslationResourceGatherer::class);
    $actor = SystemActor::Background;
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary['key'] === 'tests.self-models',
    );
    $missing = $gatherer->gather(
        'tests.self-models',
        $actor,
        ['missing_locale' => 'bg', 'per_page' => 10],
    );
    $searched = $gatherer->gather(
        'tests.self-models',
        $actor,
        ['search' => 'searchable', 'per_page' => 10],
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary['storage'])->toBe('self')
        ->and($summary['table'])->toBe('test_self_translatable_models')
        ->and($summary['translationTable'])->toBe('test_self_translatable_models')
        ->and($summary['total'])->toBe(2)
        ->and($summary['coverage']['en']['translated'])->toBe(2)
        ->and($summary['coverage']['bg']['translated'])->toBe(1)
        ->and($missing->total())->toBe(1)
        ->and($missing->items()[0]['id'])->toBe('catalog.second')
        ->and($searched->total())->toBe(1)
        ->and($searched->items()[0]['translations']['en']['name'])->toBe('Second searchable');
});

test('it centrally synchronizes and deletes grouped resource locales by logical key', function (): void {
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.managed',
        'locale' => 'en',
        'name' => 'Managed',
        'type' => 'system',
    ]);
    $actor = SystemActor::Background;
    $versioner = app(TranslationResourceVersioner::class);
    $updated = app(TranslationWriter::class)->sync(
        $model,
        ['bg' => ['name' => 'Управляван']],
        $actor,
        $versioner->version($model),
    );
    $deleted = app(TranslationWriter::class)->deleteLocale($model, 'en', $actor, $versioner->version($updated));

    expect($updated->translationResourceKey())->toBe('catalog.managed')
        ->and($updated->getAvailableLocales())->toBe(['bg', 'en'])
        ->and($deleted)->toBeTrue()
        ->and(
            TestSelfTranslatableModel::query()
                ->where('entry_key', 'catalog.managed')
                ->pluck('locale')
                ->all(),
        )->toBe(['bg']);
});

test('self-row resource scopes hide records consistently from locked writes', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.self-scoped',
        modelClass: TestSelfTranslatableModel::class,
        label: 'Scoped self-translated models',
        queryScope: static fn (Builder $query): Builder => $query->where('type', 'visible'),
    );
    $hidden = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.hidden',
        'locale' => 'en',
        'name' => 'Hidden',
        'type' => 'hidden',
    ]);

    expect(fn () => app(TranslationWriter::class)->sync(
        $hidden,
        ['bg' => ['name' => 'Скрит']],
        SystemActor::Background,
        app(TranslationResourceVersioner::class)->version($hidden),
        resourceKey: 'tests.self-scoped',
    ))->toThrow(ModelNotFoundException::class);
});

test('it bounds mutation payloads before changing grouped resources', function (): void {
    config()->set('nvl-translatable.limits.mutation_locales', 1);
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.bounded',
        'locale' => 'en',
        'name' => 'Bounded',
        'type' => 'system',
    ]);

    expect(fn () => app(TranslationWriter::class)->sync($model, [
        'en' => ['name' => 'English'],
        'bg' => ['name' => 'Bulgarian'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))->toThrow(TranslatableException::class, 'at most 1 locales')
        ->and($model->getAvailableLocales())->toBe(['en']);
});

test('self-row service mutations enforce object nesting limits before writing', function (): void {
    config()->set('nvl-translatable.limits.mutation_depth', 3);
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.nested',
        'locale' => 'en',
        'name' => 'Original',
        'type' => 'system',
    ]);
    $nestedValue = (object) [
        'first' => (object) [
            'second' => 'Too deep',
        ],
    ];

    expect(fn () => app(TranslationWriter::class)->sync($model, ['en' => ['description' => $nestedValue]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))
        ->toThrow(TranslatableException::class, 'nesting may not exceed 3 levels')
        ->and($model->fresh()?->description)->toBeNull();
});

test('the doctor validates global configuration and grouped schema invariants', function (): void {
    expect(app(TranslationDoctor::class)->inspect()->errors)->toBe([]);
    $this->artisan('nvl:translatable:doctor', ['--format' => 'json'])
        ->expectsOutputToContain('"healthy": true')
        ->assertSuccessful();

    config()->set('nvl-translatable.default_locale', 'fr');

    $report = app(TranslationDoctor::class)->inspect();

    expect($report->isHealthy())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('nvl-translatable.default_locale');

    config()->set([
        'nvl-translatable.default_locale' => 'en',
        'nvl-translatable.locales' => ['en'],
    ]);

    $localeReport = app(TranslationDoctor::class)->inspect();

    expect($localeReport->isHealthy())->toBeFalse()
        ->and(implode(' ', $localeReport->errors))
        ->toContain('Model locales must be a subset');
});

test('the doctor names canonical configuration keys in malformed option diagnostics', function (string $key, mixed $value): void {
    config()->set($key, $value);
    $report = app(TranslationDoctor::class)->inspect();

    expect($report->isHealthy())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain($key);
})->with([
    'empty locales' => ['nvl-translatable.locales', []],
    'nonstring locale' => ['nvl-translatable.locales', [42]],
    'unsupported default' => ['nvl-translatable.default_locale', 'fr'],
    'invalid fallback list' => ['nvl-translatable.fallback_locales', 'en'],
    'invalid fallback policy' => ['nvl-translatable.fallback.policy', 'invalid'],
    'invalid on-null flag' => ['nvl-translatable.fallback.on_null', 'yes'],
    'invalid mutation limit' => ['nvl-translatable.limits.mutation_locales', 0],
    'invalid transaction attempts' => ['nvl-translatable.transactions.attempts', 0],
]);

test('translation mutation diagnostics name canonical configuration keys', function (string $key, string $exceptionClass): void {
    $record = TestSelfTranslatableModel::create(['entry_key' => 'diagnostic', 'locale' => 'en', 'name' => 'Original']);
    $version = app(TranslationResourceVersioner::class)->version($record);
    config()->set($key, 0);

    expect(fn () => app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'Changed']], SystemActor::Background, $version))
        ->toThrow($exceptionClass, $key)
        ->and($record->fresh()?->name)->toBe('Original');
})->with([
    'transaction attempts' => ['nvl-translatable.transactions.attempts', TranslationResourceException::class],
    'payload locale limit' => ['nvl-translatable.limits.mutation_locales', TranslatableException::class],
]);

test('fallback definition diagnostics name the canonical null policy option', function (): void {
    config()->set('nvl-translatable.fallback.on_null', 'invalid');

    expect(fn () => (new TestSelfTranslatableModel)->translationDefinition()->shouldFallbackOnNull())
        ->toThrow(TranslatableException::class, 'nvl-translatable.fallback.on_null');
});

test('the doctor validates output formats and treats warnings as strict failures', function (): void {
    expect(app(TranslationDoctor::class)->inspect()->warnings)
        ->toContain('Configured locale [en-GB] has incomplete labels.');

    $this->artisan('nvl:translatable:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])
        ->expectsOutputToContain('"healthy": false')
        ->assertFailed();

    $this->artisan('nvl:translatable:doctor', ['--format' => 'yaml'])
        ->expectsOutput('Invalid --format option. Allowed values: text, json.')
        ->assertFailed();
});

test('the doctor validates resource metadata columns and transaction configuration', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.invalid-metadata',
        modelClass: TestSelfTranslatableModel::class,
        label: 'Invalid metadata',
        displayColumns: ['missing_column'],
    );
    config()->set('nvl-translatable.transactions.attempts', 0);
    $report = app(TranslationDoctor::class)->inspect();
    $errors = implode(' ', $report->errors);

    expect($report->isHealthy())->toBeFalse()
        ->and($errors)->toContain('transactions.attempts')
        ->and($errors)->toContain('missing_column');
});

test('the doctor rejects non-string locale columns', function (): void {
    Schema::drop('test_self_translatable_models');
    Schema::create('test_self_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->string('entry_key');
        $table->integer('locale');
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->nullable();
        $table->unique(['entry_key', 'locale'], 'self_translation_unique');
    });

    $report = app(TranslationDoctor::class)->inspect();

    expect($report->isHealthy())->toBeFalse()
        ->and(implode(' ', $report->errors))
        ->toContain('locale column [test_self_translatable_models.locale] must use a string type');
});

test('self store writes normalize the locale and reject undeclared locales and fields', function (): void {
    $model = TestSelfTranslatableModel::create(['entry_key' => 'store-validation', 'locale' => 'en', 'name' => 'English']);
    $store = app(SelfTranslationStore::class);
    $definition = $model->translationDefinition();
    $upsert = static fn (string $locale, array $attributes): mixed => DB::transaction(
        static fn () => $store->upsert($model, $definition, $locale, $attributes),
    );

    expect($upsert('en_gb', ['name' => 'British'])->getAttribute('locale'))->toBe('en-GB')
        ->and(fn () => $upsert('fr', ['name' => 'French']))->toThrow(InvalidLocaleException::class)
        ->and(fn () => $upsert('bg', ['name' => 'Name', 'id' => 999]))->toThrow(InvalidTranslatableFieldException::class)
        ->and(fn () => $upsert('bg', ['entry_key' => 'forged']))->toThrow(InvalidTranslatableFieldException::class)
        ->and(TestSelfTranslatableModel::query()->orderBy('locale')->pluck('locale')->all())->toBe(['en', 'en-GB']);
});
