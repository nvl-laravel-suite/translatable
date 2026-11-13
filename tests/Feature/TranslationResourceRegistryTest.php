<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\OwnerRegistry;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Events\TranslationResourceLocaleDeleted;
use Nvl\Translatable\Events\TranslationResourcesGathered;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\ConfiguredTranslatableModel;
use Nvl\Translatable\Tests\Support\TestConnectedTranslatableModel;
use Nvl\Translatable\Tests\Support\TestDomainManagedTranslatableModel;
use Nvl\Translatable\Tests\Support\TestMismatchedConnectionModel;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\TranslationResourceDefinition;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

beforeEach(function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('nvl-translatable.fallback_locales', ['en']);

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

test('resource admission reads current visibility from the writer rather than a stale replica', function (string $storage, string $operation): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This fixture exercises separate in-memory SQLite read and write connections.');
    }
    if ($storage === 'related') {
        $owner = TestTranslatableModel::create(['slug' => 'visible']);
        $owner->translations()->create(['locale' => 'en', 'name' => 'Private copy']);
        $tables = ['test_translatable_models', 'test_translatable_models_i18n'];
        $column = 'slug';
    } else {
        Schema::create('test_self_translatable_models', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_key');
            $table->string('locale');
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('type')->nullable();
            $table->unique(['entry_key', 'locale']);
        });
        $owner = TestSelfTranslatableModel::create(['entry_key' => 'replica', 'locale' => 'en', 'name' => 'Private copy', 'type' => 'visible']);
        $tables = ['test_self_translatable_models'];
        $column = 'type';
    }
    app(TranslationResourceRegistry::class)->register('tests.replica', $owner::class, 'Replica test',
        queryScope: static fn (Builder $query): Builder => $query->where($column, 'visible'));
    $primary = $connection->getPdo();
    $replica = new PDO('sqlite::memory:');
    foreach ($tables as $table) {
        $statement = $primary->prepare('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?');
        $statement->execute(['table', $table]);
        $replica->exec($statement->fetchColumn());
        $quotedTable = $connection->getQueryGrammar()->wrapTable($table);
        foreach ($primary->query('SELECT * FROM '.$quotedTable)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns = array_map($connection->getQueryGrammar()->wrap(...), array_keys($row));
            $replica->prepare('INSERT INTO '.$quotedTable.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
        }
    }
    $owner->forceFill([$column => 'hidden'])->save();
    $connection->setReadPdo($replica);
    try {
        $gatherer = app(TranslationResourceGatherer::class);
        if ($operation === 'find') {
            expect(fn () => $gatherer->find('tests.replica', $owner->translationResourceKey(), SystemActor::Background))->toThrow(ModelNotFoundException::class);
        } elseif ($operation === 'gather') {
            expect($gatherer->gather('tests.replica', SystemActor::Background)->total())->toBe(0);
        } else {
            $summary = collect($gatherer->summaries(SystemActor::Background))->firstWhere('key', 'tests.replica');
            expect($summary['total'])->toBe(0);
        }
    } finally {
        $connection->setReadPdo(null);
    }
})->with(['related', 'self'])->with(['find', 'gather', 'summary']);

test('native writer patches a registered owner and enforces expected revisions', function (): void {
    $owner = TestTranslatableModel::create(['slug' => 'native-writer']);
    $writer = app(TranslationWriter::class);
    $version = app(TranslationResourceVersioner::class)->version($owner);

    $updated = $writer->sync($owner, ['en' => ['name' => 'English']], SystemActor::Background, $version);

    expect($updated->translated('name', 'en'))->toBe('English')
        ->and(fn () => $writer->sync($owner, ['bg' => ['name' => 'Bulgarian']], SystemActor::Background, $version))
        ->toThrow(TranslationResourceException::class, 'changed after it was read')
        ->and($updated->getAvailableLocales())->toBe(['en']);
});

test('native writer rejects ambiguous and mismatched resource selection before persistence', function (): void {
    $owner = TestTranslatableModel::create(['slug' => 'ambiguous']);
    $version = app(TranslationResourceVersioner::class)->version($owner);
    app(TranslationResourceRegistry::class)->register('tests.second', TestTranslatableModel::class, 'Second view');
    app(TranslationResourceRegistry::class)->register('tests.other-model', TestDomainManagedTranslatableModel::class, 'Other model');
    $writer = app(TranslationWriter::class);

    expect(fn () => $writer->sync($owner, ['en' => ['name' => 'Unsafe']], SystemActor::Background, $version))
        ->toThrow(TranslationResourceException::class, 'multiple resource registrations')
        ->and(fn () => $writer->sync($owner, ['en' => ['name' => 'Unsafe']], SystemActor::Background, $version, resourceKey: 'tests.other-model'))
        ->toThrow(TranslationResourceException::class, 'does not match')
        ->and(fn () => $writer->sync($owner, ['en' => ['name' => 'Unsafe']], SystemActor::Background, $version, resourceKey: 'tests.unknown'))
        ->toThrow(TranslationResourceException::class, 'not registered')
        ->and($owner->translations()->count())->toBe(0);
});

test('native writer rejects a supplied model on noncanonical storage before opening its transaction', function (string $change): void {
    $owner = TestTranslatableModel::create(['slug' => 'canonical']);
    $version = app(TranslationResourceVersioner::class)->version($owner);
    $forged = clone $owner;
    if ($change === 'table') {
        Schema::create('test_translatable_models_shadow', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->timestamps();
        });
        DB::table('test_translatable_models_shadow')->insert(['id' => $owner->getKey(), 'slug' => 'shadow']);
        $forged->setTable('test_translatable_models_shadow');
    } else {
        config()->set('database.connections.translatable_shadow', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::purge('translatable_shadow');
        DB::connection('translatable_shadow')->getSchemaBuilder()->create('test_translatable_models', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->timestamps();
        });
        DB::connection('translatable_shadow')->table('test_translatable_models')->insert(['id' => $owner->getKey(), 'slug' => 'shadow']);
        $forged->setConnection('translatable_shadow');
    }
    $scopeCalls = 0;
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.selected-storage',
        modelClass: TestTranslatableModel::class,
        label: 'Selected storage',
        queryScope: static function (Builder $query) use (&$scopeCalls): Builder {
            $scopeCalls++;

            return $query;
        },
    );
    $transactionsStarted = 0;
    Event::listen(TransactionBeginning::class, static function () use (&$transactionsStarted): void {
        $transactionsStarted++;
    });

    expect(fn () => app(TranslationWriter::class)->sync(
        $forged,
        ['en' => ['name' => 'Unsafe']],
        SystemActor::Background,
        $version,
        resourceKey: 'tests.selected-storage',
    ))->toThrow(TranslationResourceException::class, 'canonical storage')
        ->and($scopeCalls)->toBe(0)
        ->and($transactionsStarted)->toBe(0)
        ->and($owner->translations()->count())->toBe(0);
})->with(['different table' => ['table'], 'different actual connection' => ['connection']]);

test('native writer enforces the selected resource visibility scope', function (): void {
    $owner = TestTranslatableModel::create(['slug' => 'hidden']);
    $version = app(TranslationResourceVersioner::class)->version($owner);
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.visible',
        modelClass: TestTranslatableModel::class,
        label: 'Visible only',
        queryScope: static fn (Builder $query): Builder => $query->where('slug', 'visible'),
    );

    expect(fn () => app(TranslationWriter::class)->sync(
        $owner,
        ['en' => ['name' => 'Unsafe']],
        SystemActor::Background,
        $version,
        resourceKey: 'tests.visible',
    ))->toThrow(ModelNotFoundException::class)
        ->and($owner->translations()->count())->toBe(0);
});

test('it compiles configured subclasses before building their translation resource definitions', function (): void {
    app(OwnerRegistry::class)->registerReplacement('fixture.configured-record', TestTranslatableModel::class, ConfiguredTranslatableModel::class);
    $resource = app(TranslationResourceRegistry::class)->register('tests.configured', TestTranslatableModel::class, 'Configured records');
    $prototype = $resource->newModel();
    expect($prototype)->toBeInstanceOf(ConfiguredTranslatableModel::class)
        ->and(Relation::getMorphedModel(TestTranslatableModel::class))->toBe(ConfiguredTranslatableModel::class);
    $record = $prototype->newQuery()->create(['slug' => 'configured']);
    app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'Configured value']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record));
    expect($record->translated('name', 'en'))->toBe('Configured value');
});

test('it gathers normalized translations and locale coverage without n plus one reads', function (): void {
    $first = TestTranslatableModel::create(['slug' => 'first-model']);
    $second = TestTranslatableModel::create(['slug' => 'second-model']);
    $writer = app(TranslationWriter::class);

    $writer->sync($first, [
        'en' => ['name' => 'First'],
        'bg' => ['name' => 'Първи'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($first));
    $writer->sync($second, ['en' => ['name' => 'Second']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($second));
    $second->translations()->create([
        'locale' => 'fr',
        'name' => 'Unsupported legacy row',
    ]);

    $gatherer = app(TranslationResourceGatherer::class);
    $actor = SystemActor::Background;
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary['key'] === 'tests.models',
    );
    $page = $gatherer->gather(
        'tests.models',
        $actor,
        ['missing_locale' => 'bg', 'per_page' => 10],
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary['translationTable'])->toBe('test_translatable_models_i18n')
        ->and($summary['total'])->toBe(2)
        ->and($summary['coverage']['en'])->toBe(['translated' => 2, 'missing' => 0])
        ->and($summary['coverage']['bg'])->toBe(['translated' => 1, 'missing' => 1])
        ->and($summary['coverage']['en-GB'])->toBe(['translated' => 0, 'missing' => 2])
        ->and($page->total())->toBe(1)
        ->and($page->items()[0]['id'])->toBe($second->id)
        ->and($page->items()[0]['label'])->toBe('second-model')
        ->and($page->items()[0]['translations']['en']['name'])->toBe('Second')
        ->and(array_keys($page->items()[0]['translations']))->toBe(['en'])
        ->and($page->items()[0]['missingLocales'])->toBe(['bg', 'en-GB'])
        ->and($page->items()[0]['version'])->toHaveLength(64);
});

test('summary coverage uses one aggregate for scoped related and self resources', function (string $storage, int $records): void {
    if ($storage === 'self') {
        Schema::dropIfExists('test_self_translatable_models');
        Schema::create('test_self_translatable_models', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_key');
            $table->string('locale', 35);
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('type')->nullable();
            $table->unique(['entry_key', 'locale'], 'coverage_self_locale_unique');
        });
    }
    $model = $storage === 'self' ? new TestSelfTranslatableModel : new TestTranslatableModel;
    if (getenv('NVL_FULL_DATABASE') === '1') {
        expect($model->getConnection()->getDriverName())->toBe(getenv('DB_CONNECTION'))
            ->and($model->getConnection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe(getenv('DB_CONNECTION'));
    }
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.coverage',
        modelClass: $model::class,
        label: 'Visible coverage',
        queryScope: static function (Builder $query) use ($storage): Builder {
            if ($storage === 'self') {
                return $query->where('type', 'visible')->where('locale', '!=', 'bg');
            }

            return $query->where('slug', 'visible');
        },
    );

    foreach (['visible' => $records, 'hidden' => 1] as $visibility => $count) {
        for ($index = 0; $index < $count; $index++) {
            if ($storage === 'self') {
                TestSelfTranslatableModel::create(['entry_key' => $visibility.'-'.$index, 'locale' => 'en', 'name' => null, 'type' => $visibility]);
                if ($index === 0) {
                    TestSelfTranslatableModel::create(['entry_key' => $visibility.'-'.$index, 'locale' => 'bg', 'name' => '', 'type' => $visibility]);
                }
            } else {
                $owner = TestTranslatableModel::create(['slug' => $visibility]);
                $owner->translations()->create(['locale' => 'en', 'name' => null]);
                if ($index === 0) {
                    $owner->translations()->create(['locale' => 'bg', 'name' => '']);
                }
                $owner->translations()->create(['locale' => 'fr', 'name' => 'Unsupported legacy locale']);
            }
        }
    }

    $connection = $model->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $summary = collect(app(TranslationResourceGatherer::class)->summaries(SystemActor::Background))->firstWhere('key', 'tests.coverage');
        $aggregateQueries = array_filter($connection->getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select count(')
            && in_array('visible', $query['bindings'], true));
    } finally {
        $connection->disableQueryLog();
    }

    $translatedBulgarian = $storage === 'related' && $records > 0 ? 1 : 0;
    expect($summary['total'])->toBe($records)
        ->and($summary['coverage']['en'])->toBe(['translated' => $records, 'missing' => 0])
        ->and($summary['coverage']['bg'])->toBe(['translated' => $translatedBulgarian, 'missing' => $records - $translatedBulgarian])
        ->and($summary['coverage']['en-GB'])->toBe(['translated' => 0, 'missing' => $records])
        ->and($summary['coverage'])->not->toHaveKey('fr')
        ->and($aggregateQueries)->toHaveCount(1);
})->with(['related', 'self'])->with([0, 2, 40]);

test('summary coverage retains the existing count semantics of a limited host scope', function (): void {
    $owner = TestTranslatableModel::create(['slug' => 'limited']);
    $owner->translations()->create(['locale' => 'en', 'name' => 'Visible']);
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.limited',
        modelClass: TestTranslatableModel::class,
        label: 'Limited scope',
        queryScope: static fn (Builder $query): Builder => $query->limit(0),
    );

    $summary = collect(app(TranslationResourceGatherer::class)->summaries(SystemActor::Background))->firstWhere('key', 'tests.limited');

    expect($summary['total'])->toBe(0)
        ->and($summary['coverage']['en'])->toBe(['translated' => 0, 'missing' => 0]);
});

test('summary coverage rejects immediate and deferred unions before aggregation', function (bool $deferred): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.union',
        modelClass: TestTranslatableModel::class,
        label: 'Rejected union',
        queryScope: static function (Builder $query) use ($deferred): Builder {
            if ($deferred) {
                $query->getQuery()->beforeQuery(static function (Illuminate\Database\Query\Builder $base): void {
                    $base->union(TestTranslatableModel::query()->toBase());
                });

                return $query;
            }

            return $query->union(TestTranslatableModel::query());
        },
    );

    expect(fn () => app(TranslationResourceGatherer::class)->summaries(SystemActor::Background))
        ->toThrow(TranslationResourceException::class, 'registered model, table, and connection');
})->with([false, true]);

test('it reports resources as unavailable until both persistence tables exist', function (): void {
    Schema::drop('test_translatable_models_i18n');

    $gatherer = app(TranslationResourceGatherer::class);
    $actor = SystemActor::Background;
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary['key'] === 'tests.models',
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary['available'])->toBeFalse()
        ->and($summary['total'])->toBe(0)
        ->and($summary['coverage'])->toBe([])
        ->and(fn () => $gatherer->gather('tests.models', $actor))
        ->toThrow(TranslationResourceException::class, 'until tables');
});

test('it searches only registered owner columns', function (): void {
    TestTranslatableModel::create(['slug' => 'catalog-one']);
    TestTranslatableModel::create(['slug' => 'other-record']);

    $page = app(TranslationResourceGatherer::class)->gather(
        'tests.models',
        SystemActor::Background,
        ['search' => 'catalog', 'per_page' => 10],
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['attributes']['slug'])->toBe('catalog-one');
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
    $writer->sync($visible, ['en' => ['name' => 'Visible']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($visible), resourceKey: 'tests.models');
    $writer->sync($hidden, ['en' => ['name' => 'Hidden']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($hidden), resourceKey: 'tests.models');
    $actor = SystemActor::Background;
    $gatherer = app(TranslationResourceGatherer::class);
    $summary = collect($gatherer->summaries($actor))->first(
        static fn ($summary): bool => $summary['key'] === 'tests.scoped',
    );

    expect($summary)
        ->not->toBeNull()
        ->and($summary['total'])->toBe(1)
        ->and($summary['coverage']['en']['translated'])->toBe(1)
        ->and($gatherer->gather('tests.scoped', $actor)->total())->toBe(1)
        ->and(fn () => $gatherer->find('tests.scoped', $hidden->id, $actor))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(TranslationWriter::class)->sync(
            $hidden,
            ['en' => ['name' => 'Still hidden']],
            $actor,
            app(TranslationResourceVersioner::class)->version($hidden),
            resourceKey: 'tests.scoped',
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
        SystemActor::Background,
    ))->toThrow(TranslationResourceException::class, 'preserve its registered model');
});

test('it treats search wildcard characters as literal input', function (): void {
    TestTranslatableModel::create(['slug' => 'discount-100%-off']);
    TestTranslatableModel::create(['slug' => 'discount-100X-off']);

    $page = app(TranslationResourceGatherer::class)->gather(
        'tests.models',
        SystemActor::Background,
        ['search' => '100%', 'per_page' => 10],
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['attributes']['slug'])->toBe('discount-100%-off');
});

test('it centrally patches replaces and deletes registered resource translations', function (): void {
    Event::fake([
        TranslationResourceSynced::class,
        TranslationResourceLocaleDeleted::class,
    ]);
    $model = TestTranslatableModel::create(['slug' => 'managed']);
    $writer = app(TranslationWriter::class);
    $actor = SystemActor::Background;
    $versioner = app(TranslationResourceVersioner::class);

    $updated = $writer->sync($model, [
        'en' => ['name' => 'Managed'],
        'bg' => ['name' => 'Управляван'],
    ], $actor, $versioner->version($model));
    $updatedVersion = $versioner->version($updated);

    expect($model->translations()->count())->toBe(2)
        ->and($updatedVersion)->toHaveLength(64);

    $replaced = $writer->sync($model, ['en' => ['name' => 'Only English']], $actor, $updatedVersion, TranslationSyncMode::Replace);
    $replacedVersion = $versioner->version($replaced);

    $deleted = $writer->deleteLocale($model, 'en', $actor, $replacedVersion);
    $deletedVersion = $versioner->version($model);

    expect($model->translations()->count())->toBe(0)
        ->and($deleted)->toBeTrue()
        ->and($deletedVersion)->toHaveLength(64)
        ->and($replaced->getAvailableLocales())->toBe(['en'])
        ->and($replacedVersion)->not->toBe($updatedVersion)
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

test('it discovers owning-service-only resources without bypassing their package service', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.domain-managed',
        modelClass: TestDomainManagedTranslatableModel::class,
        label: 'Domain-managed models',
        displayColumns: ['slug'],
    );
    $model = TestDomainManagedTranslatableModel::create(['slug' => 'guarded']);
    $actor = SystemActor::Background;
    $versioner = app(TranslationResourceVersioner::class);
    $summary = collect(app(TranslationResourceGatherer::class)->summaries($actor))
        ->first(
            static fn ($summary): bool => $summary['key'] === 'tests.domain-managed',
        );

    expect($summary)
        ->not->toBeNull()
        ->and($summary['mutationPolicy'])->toBe('owning-service-only')
        ->and(fn () => app(TranslationWriter::class)->sync(
            $model,
            ['en' => ['name' => 'Unauthorized write']],
            new GenericUser(['id' => 'untrusted']),
            $versioner->version($model),
        ))
        ->toThrow(TranslationResourceException::class, 'does not authorize')
        ->and(fn () => app(TranslationWriter::class)->sync(
            $model,
            ['en' => ['name' => 'Unsafe direct write']],
            $actor,
            $versioner->version($model),
        ))
        ->toThrow(TranslationResourceException::class, 'owning package service');

    $model->getConnection()->transaction(fn () => app(RelatedTranslationStore::class)->upsert(
        $model,
        $model->translationDefinition(),
        'en',
        ['name' => 'Domain write'],
    ));

    expect(fn () => app(TranslationWriter::class)->deleteLocale(
        $model,
        'en',
        $actor,
        $versioner->version($model),
    ))->toThrow(TranslationResourceException::class, 'owning package service')
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

    expect(fn () => app(TranslationWriter::class)->sync(
        $model,
        ['en' => ['name' => 'Written first'], 'bg' => []],
        SystemActor::Background,
        app(TranslationResourceVersioner::class)->version($model),
    ))->toThrow(QueryException::class)
        ->and($model->translations()->count())->toBe(0);

    Event::assertNotDispatched(TranslationResourceSynced::class);
});

test('central events carry scalar actor facts after outer commit and discard rolled back facts', function (): void {
    Event::fake([
        TranslationResourceSynced::class,
        TranslationResourceLocaleDeleted::class,
        TranslationResourcesGathered::class,
    ]);
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
        $table->timestamps();
    });
    $actor = new User;
    $actor->setAttribute('slug', 'actor');
    $actor->save();
    $actor->setAttribute('private_payload', 'do-not-serialize');
    app()->bind(TranslationResourceAuthorizer::class, static fn () => new class implements TranslationResourceAuthorizer
    {
        public function allows(Authenticatable|SystemActor $actor, TranslationResourceAbility $ability, TranslationResourceDefinition $resource, ?Model $record = null): bool
        {
            return $actor instanceof User;
        }
    });
    $model = TestTranslatableModel::create(['slug' => 'committed']);
    $writer = app(TranslationWriter::class);
    $versioner = app(TranslationResourceVersioner::class);

    DB::transaction(function () use ($writer, $model, $actor, $versioner): void {
        $writer->sync($model, ['en' => ['name' => 'Committed']], $actor, $versioner->version($model));
        Event::assertNotDispatched(TranslationResourceSynced::class);
    });

    Event::assertDispatched(TranslationResourceSynced::class, static fn (TranslationResourceSynced $event): bool => $event->actorType === User::class && $event->actorId === $actor->getKey()
        && ! str_contains(serialize($event), 'do-not-serialize'));

    $version = $versioner->version($model);
    $writer->deleteLocale($model, 'en', $actor, $version);
    Event::assertDispatched(TranslationResourceLocaleDeleted::class, static fn (TranslationResourceLocaleDeleted $event): bool => $event->actorType === User::class && $event->actorId === $actor->getKey()
        && ! str_contains(serialize($event), 'do-not-serialize'));

    app(TranslationResourceGatherer::class)->gather('tests.models', $actor);
    Event::assertDispatched(TranslationResourcesGathered::class, static fn (TranslationResourcesGathered $event): bool => $event->actorType === User::class && $event->actorId === $actor->getKey()
        && ! str_contains(serialize($event), 'do-not-serialize'));

    $rolledBack = TestTranslatableModel::create(['slug' => 'rolled-back']);
    expect(fn () => DB::transaction(function () use ($writer, $rolledBack, $actor, $versioner): void {
        $writer->sync($rolledBack, ['en' => ['name' => 'Discarded']], $actor, $versioner->version($rolledBack));
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');
    Event::assertDispatchedTimes(TranslationResourceSynced::class, 1);
    expect($rolledBack->translations()->count())->toBe(0);
});

test('it rejects undeclared fields and unknown resources', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'invalid']);

    expect(fn () => app(TranslationWriter::class)->sync(
        $model,
        ['en' => ['not_declared' => 'value']],
        SystemActor::Background,
        app(TranslationResourceVersioner::class)->version($model),
    ))->toThrow(InvalidTranslatableFieldException::class)
        ->and(fn () => app(TranslationResourceRegistry::class)->get('missing.resource'))
        ->toThrow(TranslationResourceException::class, 'not registered');
});

test('it rejects unauthorized actors and stale translation writes', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'versioned']);
    $versioner = app(TranslationResourceVersioner::class);
    $initialVersion = $versioner->version($model);
    $systemActor = SystemActor::Background;
    $untrustedActor = new GenericUser(['id' => 'untrusted']);
    $sync = app(TranslationWriter::class);

    expect(fn () => app(TranslationResourceGatherer::class)->gather(
        'tests.models',
        $untrustedActor,
    ))->toThrow(TranslationResourceException::class, 'does not authorize');

    $sync->sync($model, ['en' => ['name' => 'First revision']], $systemActor, $initialVersion);

    expect(fn () => $sync->sync($model, ['en' => ['name' => 'Stale revision']], $systemActor, $initialVersion))
        ->toThrow(TranslationResourceException::class, 'changed after it was read');
});

test('resource versions recursively canonicalize object-shaped translated values', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'canonical-version']);
    app(TranslationWriter::class)->sync($model, ['en' => ['name' => 'Versioned']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
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

    app(TranslationWriter::class)->sync($model, ['en' => ['name' => 'Connected']], SystemActor::Background, $versioner->version($model));

    expect(
        DB::connection('translatable_tests')
            ->table('test_connected_models_i18n')
            ->value('name'),
    )->toBe('Connected')
        ->and(
            DB::connection()->table('test_translatable_models_i18n')->count(),
        )->toBe(0);

    $mismatched = TestMismatchedConnectionModel::query()->findOrFail($model->id);

    expect(fn () => app(TranslationWriter::class)->sync($mismatched, ['en' => ['name' => 'Cross-connection']], SystemActor::Background, 'unavailable-revision'))
        ->toThrow(TranslationResourceException::class, 'not registered');
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

test('doctor rejects partial unique indexes on unowned related translation tables', function (): void {
    app(TranslationResourceRegistry::class)->register(
        key: 'tests.partial-index',
        modelClass: TestTranslatableModel::class,
        label: 'Partial index',
    );

    foreach (Schema::getIndexes('test_translatable_models_i18n') as $index) {
        if (($index['unique'] ?? false) === true && ! ($index['primary'] ?? false)) {
            Schema::table('test_translatable_models_i18n', function (Blueprint $table) use ($index): void {
                $table->dropUnique((string) $index['name']);
            });
        }
    }

    DB::statement('CREATE UNIQUE INDEX test_partial_locale_unique ON test_translatable_models_i18n (test_translatable_model_id, locale) WHERE name IS NOT NULL');

    $report = app(TranslationDoctor::class)->inspect();

    expect($report->isHealthy())->toBeFalse()
        ->and(implode("\n", $report->errors))->toContain('requires a unique index');
});
