<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Translatable\Actions\DeleteTranslationResourceLocaleAction;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\DeleteTranslationLocaleData;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Enums\TranslationStorageStrategy;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\TranslationResourceQuery;

beforeEach(function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('translatable.fallback_locales', ['en']);

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

    $bulgarian = $writer->upsert($model, 'bg', [
        'name' => 'Добре дошли',
        'description' => 'Българско описание',
    ]);
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

    expect(fn () => $writer->upsert($model, 'bg', ['entry_key' => 'changed']))
        ->toThrow(InvalidTranslatableFieldException::class)
        ->and(fn () => $writer->delete($model, 'en'))
        ->toThrow(TranslatableException::class, 'final locale row');

    $writer->upsert($model, 'bg', ['name' => 'Защитен']);
    $writer->replace($model, ['bg' => ['name' => 'Само български']]);

    expect(
        TestSelfTranslatableModel::query()
            ->where('entry_key', 'catalog.protected')
            ->pluck('locale')
            ->all(),
    )->toBe(['bg']);
});

test('self-row convenience mutations preserve identity and loaded group state', function (): void {
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.convenience',
        'locale' => 'en',
        'name' => 'English',
        'type' => 'system',
    ]);
    $model->setRelation('translations', $model->getAllTranslations());

    $model->setTranslation(['name' => 'Български'], 'bg');
    $model->cloneTranslation('en', 'en-GB');

    expect($model->getAllTranslations())->toHaveCount(3)
        ->and($model->translated('name', 'bg'))->toBe('Български')
        ->and($model->translated('name', 'en-GB'))->toBe('English')
        ->and($model->deleteTranslation('en-GB'))->toBeTrue()
        ->and($model->deleteTranslation('en-GB'))->toBeFalse()
        ->and($model->deleteTranslation('bg'))->toBeTrue()
        ->and($model->deleteTranslation('bg'))->toBeFalse()
        ->and(fn () => $model->deleteTranslation('en'))
        ->toThrow(TranslatableException::class, 'final locale row')
        ->and(fn () => (new TestSelfTranslatableModel([
            'entry_key' => 'catalog.unsaved',
        ]))->setTranslation(['name' => 'Unsaved'], 'en'))
        ->toThrow(TranslatableException::class, 'persisted representative');

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
    app(TranslationWriter::class)->upsert($first, 'bg', ['name' => 'Първи']);
    TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.second',
        'locale' => 'en',
        'name' => 'Second searchable',
        'type' => 'system',
    ]);
    $gatherer = app(TranslationResourceGatherer::class);
    $actor = TranslationActorData::system('test');
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary->key === 'tests.self-models',
    );
    $missing = $gatherer->gather(
        'tests.self-models',
        $actor,
        new TranslationResourceQuery(missingLocale: 'bg', perPage: 10),
    );
    $searched = $gatherer->gather(
        'tests.self-models',
        $actor,
        new TranslationResourceQuery(search: 'searchable', perPage: 10),
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary->storage)->toBe('self')
        ->and($summary->table)->toBe('test_self_translatable_models')
        ->and($summary->translationTable)->toBe('test_self_translatable_models')
        ->and($summary->total)->toBe(2)
        ->and($summary->coverage['en']->translated)->toBe(2)
        ->and($summary->coverage['bg']->translated)->toBe(1)
        ->and($missing->total())->toBe(1)
        ->and($missing->items()[0]->id)->toBe('catalog.second')
        ->and($searched->total())->toBe(1)
        ->and($searched->items()[0]->translations['en']['name'])->toBe('Second searchable');
});

test('it centrally synchronizes and deletes grouped resource locales by logical key', function (): void {
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.managed',
        'locale' => 'en',
        'name' => 'Managed',
        'type' => 'system',
    ]);
    $actor = TranslationActorData::system('test');
    $versioner = app(TranslationResourceVersioner::class);
    $updated = app(SyncTranslationResourceAction::class)->execute(
        'tests.self-models',
        'catalog.managed',
        new TranslationMutationData(
            translations: ['bg' => ['name' => 'Управляван']],
            expectedVersion: $versioner->version($model),
        ),
        $actor,
    );
    $deleted = app(DeleteTranslationResourceLocaleAction::class)->execute(
        'tests.self-models',
        'catalog.managed',
        new DeleteTranslationLocaleData('en', $updated->version),
        $actor,
    );

    expect($updated->id)->toBe('catalog.managed')
        ->and($updated->locales)->toBe(['bg'])
        ->and($deleted->deleted)->toBeTrue()
        ->and($deleted->id)->toBe('catalog.managed')
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

    expect(fn () => app(SyncTranslationResourceAction::class)->execute(
        'tests.self-scoped',
        'catalog.hidden',
        new TranslationMutationData(
            translations: ['bg' => ['name' => 'Скрит']],
            expectedVersion: app(TranslationResourceVersioner::class)->version($hidden),
        ),
        TranslationActorData::system('test'),
    ))->toThrow(ModelNotFoundException::class);
});

test('it bounds mutation payloads before changing grouped resources', function (): void {
    config()->set('translatable.limits.mutation_locales', 1);
    $model = TestSelfTranslatableModel::create([
        'entry_key' => 'catalog.bounded',
        'locale' => 'en',
        'name' => 'Bounded',
        'type' => 'system',
    ]);

    expect(fn () => app(TranslationWriter::class)->patch($model, [
        'en' => ['name' => 'English'],
        'bg' => ['name' => 'Bulgarian'],
    ]))->toThrow(TranslatableException::class, 'at most 1 locales')
        ->and($model->getAvailableLocales())->toBe(['en']);
});

test('self-row convenience mutations enforce object nesting limits before writing', function (): void {
    config()->set('translatable.limits.mutation_depth', 3);
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

    expect(fn () => $model->setTranslation(['description' => $nestedValue], 'en'))
        ->toThrow(TranslatableException::class, 'nesting may not exceed 3 levels')
        ->and($model->fresh()?->description)->toBeNull();
});

test('the doctor validates global configuration and grouped schema invariants', function (): void {
    $this->artisan('nvl:translatable:doctor', ['--format' => 'json'])
        ->expectsOutputToContain('"healthy": true')
        ->assertSuccessful();

    config()->set('translatable.default_locale', 'fr');

    $report = app(TranslationDoctor::class)->inspect();

    expect($report->isHealthy())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('default_locale');

    config()->set([
        'translatable.default_locale' => 'en',
        'translatable.locales' => ['en'],
    ]);

    $localeReport = app(TranslationDoctor::class)->inspect();

    expect($localeReport->isHealthy())->toBeFalse()
        ->and(implode(' ', $localeReport->errors))
        ->toContain('Model locales must be a subset');
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
    config()->set('translatable.transactions.attempts', 0);
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
