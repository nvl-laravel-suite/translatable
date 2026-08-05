<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Translatable\Actions\DeleteTranslationResourceLocaleAction;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\DeleteTranslationLocaleData;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Events\TranslationResourceLocaleDeleted;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestConnectedTranslatableModel;
use Nvl\Translatable\Tests\Support\TestDomainManagedTranslatableModel;
use Nvl\Translatable\Tests\Support\TestMismatchedConnectionModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\TranslationResourceQuery;

beforeEach(function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('translatable.fallback_locales', ['en']);

    Schema::dropIfExists('test_translatable_models_i18n');
    Schema::dropIfExists('test_translatable_models');

    Schema::create('test_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->nullable();
        $table->timestamps();
    });

    Schema::create('test_translatable_models_i18n', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('test_translatable_model_id')
            ->constrained('test_translatable_models')
            ->cascadeOnDelete();
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->unique(['test_translatable_model_id', 'locale'], 'resource_translation_unique');
    });

    app(TranslationResourceRegistry::class)->register(
        key: 'tests.models',
        modelClass: TestTranslatableModel::class,
        label: 'Test models',
        searchableColumns: ['slug'],
        displayColumns: ['slug'],
        orderColumn: 'id',
    );
});

test('it exposes one registry for every resource registered in the isolated application', function (): void {
    $keys = app(TranslationResourceRegistry::class)->keys();

    expect($keys)->toContain('tests.models');
});

test('it gathers normalized translations and locale coverage without n plus one reads', function (): void {
    $first = TestTranslatableModel::create(['slug' => 'first-model']);
    $second = TestTranslatableModel::create(['slug' => 'second-model']);
    $writer = app(TranslationWriter::class);

    $writer->patch($first, [
        'en' => ['name' => 'First'],
        'bg' => ['name' => 'Първи'],
    ]);
    $writer->upsert($second, 'en', ['name' => 'Second']);
    $second->translations()->create([
        'locale' => 'EN',
        'name' => 'Non-canonical legacy row',
    ]);

    $gatherer = app(TranslationResourceGatherer::class);
    $actor = TranslationActorData::system('test');
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary->key === 'tests.models',
    );
    $page = $gatherer->gather(
        'tests.models',
        $actor,
        new TranslationResourceQuery(missingLocale: 'bg', perPage: 10),
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary->translationTable)->toBe('test_translatable_models_i18n')
        ->and($summary->total)->toBe(2)
        ->and($summary->coverage['en']->toArray())->toBe(['translated' => 2, 'missing' => 0])
        ->and($summary->coverage['bg']->toArray())->toBe(['translated' => 1, 'missing' => 1])
        ->and($summary->coverage['en-GB']->toArray())->toBe(['translated' => 0, 'missing' => 2])
        ->and($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($second->id)
        ->and($page->items()[0]->label)->toBe('second-model')
        ->and($page->items()[0]->translations['en']['name'])->toBe('Second')
        ->and(array_keys($page->items()[0]->translations))->toBe(['en'])
        ->and($page->items()[0]->missingLocales)->toBe(['bg', 'en-GB'])
        ->and($page->items()[0]->version)->toHaveLength(64);
});

test('it reports resources as unavailable until both persistence tables exist', function (): void {
    Schema::drop('test_translatable_models_i18n');

    $gatherer = app(TranslationResourceGatherer::class);
    $actor = TranslationActorData::system('test');
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary->key === 'tests.models',
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary->available)->toBeFalse()
        ->and($summary->total)->toBe(0)
        ->and($summary->coverage)->toBe([])
        ->and(fn () => $gatherer->gather('tests.models', $actor))
        ->toThrow(TranslationResourceException::class, 'until tables');
});

test('it searches only registered owner columns', function (): void {
    TestTranslatableModel::create(['slug' => 'catalog-one']);
    TestTranslatableModel::create(['slug' => 'other-record']);

    $page = app(TranslationResourceGatherer::class)->gather(
        'tests.models',
        TranslationActorData::system('test'),
        new TranslationResourceQuery(search: 'catalog', perPage: 10),
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->attributes['slug'])->toBe('catalog-one');
});

test('it applies resource query scopes to coverage reads and locked writes', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.scoped',
        modelClass: TestTranslatableModel::class,
        label: 'Scoped models',
        displayColumns: ['slug'],
        queryScope: static fn (Builder $query): Builder => $query->where('slug', 'visible'),
    );
    $visible = TestTranslatableModel::create(['slug' => 'visible']);
    $hidden = TestTranslatableModel::create(['slug' => 'hidden']);
    $writer = app(TranslationWriter::class);
    $writer->upsert($visible, 'en', ['name' => 'Visible']);
    $writer->upsert($hidden, 'en', ['name' => 'Hidden']);
    $actor = TranslationActorData::system('test');
    $gatherer = app(TranslationResourceGatherer::class);
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary->key === 'tests.scoped',
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary->total)->toBe(1)
        ->and($summary->coverage['en']->translated)->toBe(1)
        ->and($gatherer->gather('tests.scoped', $actor)->total())->toBe(1)
        ->and(fn () => $gatherer->find('tests.scoped', $hidden->id, $actor))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SyncTranslationResourceAction::class)->execute(
            'tests.scoped',
            $hidden->id,
            new TranslationMutationData(
                ['en' => ['name' => 'Still hidden']],
                app(TranslationResourceVersioner::class)->version($hidden),
            ),
            $actor,
        ))
        ->toThrow(ModelNotFoundException::class);
});

test('resource query scopes cannot switch the registered model boundary', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.invalid-scope',
        modelClass: TestTranslatableModel::class,
        label: 'Invalid scope',
        queryScope: static fn (Builder $query): Builder => TestDomainManagedTranslatableModel::query(),
    );

    expect(fn () => app(TranslationResourceGatherer::class)->gather(
        'tests.invalid-scope',
        TranslationActorData::system('test'),
    ))->toThrow(TranslationResourceException::class, 'preserve its registered model');
});

test('it treats search wildcard characters as literal input', function (): void {
    TestTranslatableModel::create(['slug' => 'discount-100%-off']);
    TestTranslatableModel::create(['slug' => 'discount-100X-off']);

    $page = app(TranslationResourceGatherer::class)->gather(
        'tests.models',
        TranslationActorData::system('test'),
        new TranslationResourceQuery(search: '100%', perPage: 10),
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->attributes['slug'])->toBe('discount-100%-off');
});

test('it centrally patches replaces and deletes registered resource translations', function (): void {
    Event::fake([
        TranslationResourceSynced::class,
        TranslationResourceLocaleDeleted::class,
    ]);
    $model = TestTranslatableModel::create(['slug' => 'managed']);
    $sync = app(SyncTranslationResourceAction::class);
    $actor = TranslationActorData::system('test');
    $versioner = app(TranslationResourceVersioner::class);

    $updated = $sync->execute(
        'tests.models',
        $model->id,
        new TranslationMutationData(
            translations: [
                'en' => ['name' => 'Managed'],
                'bg' => ['name' => 'Управляван'],
            ],
            expectedVersion: $versioner->version($model),
        ),
        $actor,
    );

    expect($model->translations()->count())->toBe(2)
        ->and($updated->version)->toHaveLength(64);

    $replaced = $sync->execute(
        'tests.models',
        $model->id,
        new TranslationMutationData(
            translations: ['en' => ['name' => 'Only English']],
            expectedVersion: $updated->version,
            mode: TranslationSyncMode::Replace,
        ),
        $actor,
    );

    $deletion = app(DeleteTranslationResourceLocaleAction::class)->execute(
        'tests.models',
        $model->id,
        new DeleteTranslationLocaleData('en', $replaced->version),
        $actor,
    );

    expect($model->translations()->count())->toBe(0)
        ->and($deletion->deleted)->toBeTrue()
        ->and($deletion->version)->toHaveLength(64)
        ->and($replaced->locales)->toBe(['en'])
        ->and($replaced->version)->not->toBe($updated->version)
        ->and($deletion->resource)->toBe('tests.models')
        ->and($deletion->id)->toBe($model->id)
        ->and($deletion->locale)->toBe('en')
        ->and($model->translations()->count())->toBe(0);

    Event::assertDispatched(
        TranslationResourceSynced::class,
        static fn (TranslationResourceSynced $event): bool => $event->resource === 'tests.models'
            && $event->ownerId === $model->id
            && $event->locales === ['en', 'bg']
            && $event->mode === TranslationSyncMode::Patch,
    );
    Event::assertDispatched(
        TranslationResourceLocaleDeleted::class,
        static fn (TranslationResourceLocaleDeleted $event): bool => $event->locale === 'en'
            && $event->ownerId === $model->id,
    );
});

test('it discovers domain-managed resources without bypassing their package actions', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.domain-managed',
        modelClass: TestDomainManagedTranslatableModel::class,
        label: 'Domain-managed models',
        displayColumns: ['slug'],
    );
    $model = TestDomainManagedTranslatableModel::create(['slug' => 'guarded']);
    $actor = TranslationActorData::system('test');
    $versioner = app(TranslationResourceVersioner::class);
    $summary = collect(app(TranslationResourceGatherer::class)->summaries($actor))
        ->first(
            static fn ($summary): bool => $summary->key === 'tests.domain-managed',
        );

    expect($summary)
        ->not->toBeNull()
        ->and($summary->mutationPolicy)->toBe('domain-action-only')
        ->and(fn () => app(SyncTranslationResourceAction::class)->execute(
            'tests.domain-managed',
            $model->id,
            new TranslationMutationData(
                ['en' => ['name' => 'Unauthorized write']],
                $versioner->version($model),
            ),
            new TranslationActorData('user', 'untrusted'),
        ))
        ->toThrow(TranslationResourceException::class, 'does not authorize')
        ->and(fn () => app(SyncTranslationResourceAction::class)->execute(
            'tests.domain-managed',
            $model->id,
            new TranslationMutationData(
                ['en' => ['name' => 'Unsafe direct write']],
                $versioner->version($model),
            ),
            $actor,
        ))
        ->toThrow(TranslationResourceException::class, 'package domain action');

    app(TranslationWriter::class)->upsert($model, 'en', ['name' => 'Domain write']);

    expect(fn () => app(DeleteTranslationResourceLocaleAction::class)->execute(
        'tests.domain-managed',
        $model->id,
        new DeleteTranslationLocaleData('en', $versioner->version($model)),
        $actor,
    ))->toThrow(TranslationResourceException::class, 'package domain action')
        ->and($model->translations()->count())->toBe(1);
});

test('central writes roll back every locale and event when persistence fails', function (): void {
    Event::fake([TranslationResourceSynced::class]);
    Schema::drop('test_translatable_models_i18n');
    Schema::create('test_translatable_models_i18n', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('test_translatable_model_id')
            ->constrained('test_translatable_models')
            ->cascadeOnDelete();
        $table->string('locale', 35);
        $table->string('name');
        $table->string('description')->nullable();
        $table->unique(
            ['test_translatable_model_id', 'locale'],
            'resource_translation_unique',
        );
    });
    $model = TestTranslatableModel::create(['slug' => 'atomic']);

    expect(fn () => app(SyncTranslationResourceAction::class)->execute(
        'tests.models',
        $model->id,
        new TranslationMutationData(
            translations: [
                'en' => ['name' => 'Written first'],
                'bg' => [],
            ],
            expectedVersion: app(TranslationResourceVersioner::class)->version($model),
        ),
        TranslationActorData::system('test'),
    ))->toThrow(QueryException::class)
        ->and($model->translations()->count())->toBe(0);

    Event::assertNotDispatched(TranslationResourceSynced::class);
});

test('it rejects undeclared fields and unknown resources', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'invalid']);

    expect(fn () => app(SyncTranslationResourceAction::class)->execute(
        'tests.models',
        $model->id,
        new TranslationMutationData(
            ['en' => ['not_declared' => 'value']],
            app(TranslationResourceVersioner::class)->version($model),
        ),
        TranslationActorData::system('test'),
    ))->toThrow(TranslationResourceException::class, 'undeclared fields')
        ->and(fn () => app(TranslationResourceRegistry::class)->get('missing.resource'))
        ->toThrow(TranslationResourceException::class, 'not registered');
});

test('it rejects unauthorized actors and stale translation writes', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'versioned']);
    $versioner = app(TranslationResourceVersioner::class);
    $initialVersion = $versioner->version($model);
    $systemActor = TranslationActorData::system('test');
    $untrustedActor = new TranslationActorData('user', 'untrusted');
    $sync = app(SyncTranslationResourceAction::class);

    expect(fn () => app(TranslationResourceGatherer::class)->gather(
        'tests.models',
        $untrustedActor,
    ))->toThrow(TranslationResourceException::class, 'does not authorize');

    $sync->execute(
        'tests.models',
        $model->id,
        new TranslationMutationData(
            ['en' => ['name' => 'First revision']],
            $initialVersion,
        ),
        $systemActor,
    );

    expect(fn () => $sync->execute(
        'tests.models',
        $model->id,
        new TranslationMutationData(
            ['en' => ['name' => 'Stale revision']],
            $initialVersion,
        ),
        $systemActor,
    ))->toThrow(TranslationResourceException::class, 'changed after it was read');
});

test('resource versions recursively canonicalize object-shaped translated values', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'canonical-version']);
    app(TranslationWriter::class)->upsert($model, 'en', ['name' => 'Versioned']);
    $model->load('translations');
    $translation = $model->translations->firstOrFail();
    $translation->setAttribute('description', (object) ['second' => 2, 'first' => 1]);
    $versioner = app(TranslationResourceVersioner::class);
    $firstVersion = $versioner->version($model);
    $translation->setAttribute('description', (object) ['first' => 1, 'second' => 2]);

    expect($versioner->version($model))->toBe($firstVersion);
});

test('it permits idempotent registration and rejects conflicting registry keys', function (): void {
    $registry = app(TranslationResourceRegistry::class);

    $registry->register(
        key: 'tests.models',
        modelClass: TestTranslatableModel::class,
        label: 'Test models',
        searchableColumns: ['slug'],
        displayColumns: ['slug'],
        orderColumn: 'id',
    );

    expect(fn () => $registry->register(
        key: 'tests.models',
        modelClass: TestTranslatableModel::class,
        label: 'Conflicting label',
    ))->toThrow(TranslationResourceException::class, 'already registered');
});

test('the gather command emits registered resources as json', function (): void {
    $this->artisan('nvl:translatable:gather', ['--json' => true])
        ->expectsOutputToContain('"key": "tests.models"')
        ->assertSuccessful();
});

test('it mutates resources on their declared non-default connection', function (): void {
    config()->set('database.connections.translatable_tests', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('translatable_tests');
    $schema = DB::connection('translatable_tests')->getSchemaBuilder();
    $schema->create('test_connected_models', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->nullable();
        $table->timestamps();
    });
    $schema->create('test_connected_models_i18n', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('test_connected_model_id')
            ->constrained('test_connected_models')
            ->cascadeOnDelete();
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->unique(
            ['test_connected_model_id', 'locale'],
            'connected_translation_unique',
        );
    });
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.connected',
        modelClass: TestConnectedTranslatableModel::class,
        label: 'Connected models',
        searchableColumns: ['slug'],
        displayColumns: ['slug'],
    );
    $model = TestConnectedTranslatableModel::create(['slug' => 'connected']);
    $versioner = app(TranslationResourceVersioner::class);

    app(SyncTranslationResourceAction::class)->execute(
        'tests.connected',
        $model->id,
        new TranslationMutationData(
            translations: ['en' => ['name' => 'Connected']],
            expectedVersion: $versioner->version($model),
        ),
        TranslationActorData::system('test'),
    );

    expect(
        DB::connection('translatable_tests')
            ->table('test_connected_models_i18n')
            ->value('name'),
    )->toBe('Connected')
        ->and(
            DB::connection()->table('test_translatable_models_i18n')->count(),
        )->toBe(0);

    $mismatched = TestMismatchedConnectionModel::query()->findOrFail($model->id);

    expect(fn () => app(TranslationWriter::class)->upsert(
        $mismatched,
        'en',
        ['name' => 'Cross-connection'],
    ))->toThrow(TranslatableException::class, 'same connection');
});

test('it rejects related resources spanning multiple database connections', function (): void {
    config()->set('database.connections.translatable_tests', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('translatable_tests');

    expect(fn () => app(TranslationResourceRegistry::class)->register(
        key: 'tests.mismatched',
        modelClass: TestMismatchedConnectionModel::class,
        label: 'Mismatched models',
    ))->toThrow(TranslationResourceException::class, 'same connection');
});
