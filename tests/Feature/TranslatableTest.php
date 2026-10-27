<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\TranslationOwnership;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTimestampedTranslation;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModelTranslation;
use Nvl\Translatable\TranslatableOptions;

beforeEach(function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('nvl-translatable.fallback_locales', ['en']);

    app(ContentLocale::class)->reset();

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
        $table->unique(
            ['test_translatable_model_id', 'locale'],
            'translatable_unique',
        );
    });

    app(TranslationResourceRegistry::class)->register('tests.models', TestTranslatableModel::class, 'Test models');
});

test('it writes and explicitly resolves translated attributes', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, ['en' => ['name' => 'English Name']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $writer->sync($model, ['bg' => ['name' => 'Bulgarian Name']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    expect($model->translated('name', 'en'))->toBe('English Name')
        ->and($model->translated('name', 'bg'))->toBe('Bulgarian Name')
        ->and($model->getAttribute('name'))->toBeNull();
});

test('it resolves missing rows and null fields through deterministic fallback', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, ['en' => [
        'name' => 'English Name',
        'description' => 'English description',
    ]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $writer->sync($model, ['bg' => [
        'name' => 'Bulgarian Name',
        'description' => null,
    ]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    $nullFieldResolution = $model->resolveTranslation('description', 'bg');
    $missingRowResolution = $model->resolveTranslation('name', 'en-GB');

    expect($nullFieldResolution->value)->toBe('English description')
        ->and($nullFieldResolution->resolvedLocale)->toBe('en')
        ->and($nullFieldResolution->usedFallback())->toBeTrue()
        ->and($missingRowResolution->value)->toBe('English Name')
        ->and($missingRowResolution->requestedTranslationExists)->toBeFalse();
});

test('it resolves the normalized base locale before configured fallbacks', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'base-locale']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, ['en' => ['name' => 'Base English']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $writer->sync($model, ['bg' => ['name' => 'Configured fallback']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    config()->set('nvl-translatable.fallback_locales', ['bg']);

    $resolution = $model->resolveTranslation('name', 'en-GB');

    expect($resolution->value)->toBe('Base English')
        ->and($resolution->resolvedLocale)->toBe('en');
});

test('it uses a deterministic persisted locale when any-available fallback is explicit', function (): void {
    config()->set('nvl-translatable.default_locale', 'en');
    config()->set('nvl-translatable.fallback_locales', []);
    config()->set(
        'nvl-translatable.fallback.policy',
        TranslationFallbackPolicy::AnyAvailable->value,
    );

    $model = TestTranslatableModel::create(['slug' => 'first-available']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, ['bg' => ['name' => 'Bulgarian']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    $resolution = $model->resolveTranslation('name', 'en-GB');

    expect($resolution->value)->toBe('Bulgarian')
        ->and($resolution->resolvedLocale)->toBe('bg');
});

test('it never falls back when exact-only resolution is configured', function (): void {
    config()->set(
        'nvl-translatable.fallback.policy',
        TranslationFallbackPolicy::ExactOnly->value,
    );
    $model = TestTranslatableModel::create(['slug' => 'exact-only']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, ['en' => ['name' => 'English']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    expect($model->resolveTranslation('name', 'bg')->isMissing())->toBeTrue()
        ->and($model->getTranslation('bg', withFallback: false))->toBeNull();
});

test('it treats an empty string as an intentional translated value', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, ['en' => ['description' => 'Fallback description']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $writer->sync($model, ['bg' => ['description' => '']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    $resolution = $model->resolveTranslation('description', 'bg');

    expect($resolution->value)->toBe('')
        ->and($resolution->resolvedLocale)->toBe('bg')
        ->and($resolution->usedFallback())->toBeFalse();
});

test('it uses request-scoped content locale when no locale is passed', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, [
        'en' => ['name' => 'English Name'],
        'bg' => ['name' => 'Bulgarian Name'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    app(ContentLocale::class)->set('bg');

    expect($model->translated('name'))->toBe('Bulgarian Name');
});

test('it falls back when the request locale is unavailable for the model', function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB', 'fr']);
    app(ContentLocale::class)->set('fr');
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    app(TranslationWriter::class)->sync($model, ['en' => ['name' => 'English Name']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    expect($model->getCurrentLocale())->toBe('en')
        ->and($model->translated('name'))->toBe('English Name');
});

test('it patches and replaces locale sets explicitly', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, [
        'en' => ['name' => 'English Name'],
        'bg' => ['name' => 'Bulgarian Name'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $writer->sync($model, [
        'en' => ['description' => 'English description'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));

    expect($model->getAvailableLocales())->toEqualCanonicalizing(['en', 'bg'])
        ->and($model->translated('name', 'en'))->toBe('English Name');

    $writer->sync($model, [
        'bg' => ['name' => 'Само български'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model), TranslationSyncMode::Replace);

    expect($model->getAvailableLocales())->toBe(['bg'])
        ->and($model->translated('name', 'bg'))->toBe('Само български');
});

test('it returns normalized available locales in deterministic order', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'locale-order']);
    $writer = app(TranslationWriter::class);

    $writer->sync($model, [
        'en-GB' => ['name' => 'British'],
        'en' => ['name' => 'English'],
        'bg' => ['name' => 'Bulgarian'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $model->translations()->create([
        'locale' => 'fr',
        'name' => 'Unsupported legacy row',
    ]);

    expect($model->getAvailableLocales())->toBe(['bg', 'en', 'en-GB']);
});

test('it scopes queries by a validated translated attribute', function (): void {
    $first = TestTranslatableModel::create(['slug' => 'slug-1']);
    $second = TestTranslatableModel::create(['slug' => 'slug-2']);
    $writer = app(TranslationWriter::class);

    $writer->sync($first, ['en' => ['name' => 'Apple']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($first));
    $writer->sync($second, ['bg' => ['name' => 'Ябълка']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($second));

    $results = TestTranslatableModel::whereTranslated('name', 'Apple', locale: 'en')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->getKey())->toBe($first->getKey());
});

test('it exposes unambiguous null and non-null translated field scopes', function (): void {
    $nullDescription = TestTranslatableModel::create(['slug' => 'null-description']);
    $withDescription = TestTranslatableModel::create(['slug' => 'with-description']);
    $writer = app(TranslationWriter::class);
    $writer->sync($nullDescription, ['en' => ['name' => 'Null', 'description' => null]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($nullDescription));
    $writer->sync($withDescription, ['en' => ['name' => 'Present', 'description' => 'Value']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($withDescription));

    expect(
        TestTranslatableModel::whereTranslationNull('description', 'en')->pluck('slug')->all(),
    )->toBe(['null-description'])
        ->and(
            TestTranslatableModel::whereTranslationNotNull('description', 'en')->pluck('slug')->all(),
        )->toBe(['with-description']);
});

test('it rejects undeclared fields in reads and query scopes', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'slug-1']);

    expect(fn () => $model->translated('slug', 'en'))
        ->toThrow(InvalidTranslatableFieldException::class)
        ->and(fn () => TestTranslatableModel::whereTranslated('slug', 'value', locale: 'en')->get())
        ->toThrow(InvalidTranslatableFieldException::class);
});

test('it validates a complete write map before changing translation rows', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'slug-1']);
    $writer = app(TranslationWriter::class);

    expect(fn () => $writer->sync($model, [
        'en' => ['name' => 'Valid'],
        'bg' => ['slug' => 'Not translatable'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))->toThrow(InvalidTranslatableFieldException::class)
        ->and($model->translations()->count())->toBe(0)
        ->and(fn () => $writer->sync(new TestTranslatableModel, ['en' => ['name' => 'Unsaved']], SystemActor::Background, 'missing-revision'))
        ->toThrow(TranslatableException::class, 'persisted owner');
});

test('it rejects malformed write-map shapes before changing translation rows', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'invalid-shape']);
    $writer = app(TranslationWriter::class);

    expect(fn () => $writer->sync($model, [
        0 => ['name' => 'Missing locale key'],
    ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))->toThrow(TranslatableException::class, 'string locale keys')
        ->and(fn () => $writer->sync($model, [
            'en' => 'not-an-attribute-map',
        ], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model)))->toThrow(TranslatableException::class, 'field-keyed array')
        ->and($model->translations()->count())->toBe(0);
});

test('it eager loads translated rows in bounded queries while freshly admitting each loaded read', function (): void {
    $writer = app(TranslationWriter::class);
    $create = static function (int $index) use ($writer): void {
        $model = TestTranslatableModel::create(['slug' => "slug-{$index}"]);
        $writer->sync($model, ['en' => ['name' => "Name {$index}"]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    };
    $measure = static function (): array {
        $owner = new TestTranslatableModel;
        app(TranslationOwnership::class)->assertOwner($owner, $owner->translationDefinition());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $models = TestTranslatableModel::withResolvedTranslations('bg')->get();
        $names = $models->map(
            static fn (TestTranslatableModel $model): mixed => $model->translated('name', 'bg'),
        );
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return [
            'total' => count($queries),
            'translation_reads' => count(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'select') && str_contains($query, '"test_translatable_models_i18n"')
                && ! str_contains($query, 'sqlite_master'))),
            'admission_probes' => count(array_filter($queries, static fn (string $query): bool => str_contains($query, 'sqlite_master'))),
            'names' => $names->all(),
        ];
    };

    $create(1);
    $single = $measure();

    foreach (range(2, 25) as $index) {
        $create($index);
    }

    $populated = $measure();

    expect($populated['names'])->toHaveCount(25)
        ->and($populated['names'][0])->toBe('Name 1')
        ->and($single['translation_reads'])->toBe(1)
        ->and($populated['translation_reads'])->toBe($single['translation_reads'])
        ->and($populated['admission_probes'])->toBeGreaterThanOrEqual(25)
        ->and($populated['total'] - $single['total'])->toBeLessThanOrEqual(24 * 3);
});

test('it admits loaded related storage once per read and freshly rejects later adoption', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'read-admission']);
    app(TranslationWriter::class)->sync($model, ['en' => ['name' => 'Name']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    $model->load('translations');
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($model->translations->sole()->name)->toBe('Name');
    $probes = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $query): bool => str_contains($query, 'sqlite_master'));
    DB::disableQueryLog();
    expect($probes)->toHaveCount(1);

    Schema::create(OwnershipStorageTables::Markers, static function (Blueprint $table): void {
        $table->string('resource');
        $table->string('physical_table');
    });
    DB::table(OwnershipStorageTables::Markers)->insert(['resource' => 'tests.claimed', 'physical_table' => $model->getTable()]);
    expect(fn () => $model->translations)->toThrow(OwnershipSchemaNotReady::class);
});

test('it validates loaded translation batches with constant fresh storage probes', function (): void {
    $writer = app(TranslationWriter::class);
    foreach (range(1, 25) as $index) {
        $model = TestTranslatableModel::create(['slug' => 'batch-'.$index]);
        $writer->sync($model, ['en' => ['name' => 'Name '.$index]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($model));
    }
    $models = TestTranslatableModel::with('translations')->orderBy('id')->get()->all();
    $store = app(RelatedTranslationStore::class);
    $measure = static function (array $owners) use ($store): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $store->rowsForOwners($owners);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [$result, count($queries)];
    };
    [$single, $singleQueries] = $measure([$models[0]]);
    [$batch, $batchQueries] = $measure($models);
    expect($single[0]->sole()->name)->toBe('Name 1')
        ->and($batch)->toHaveCount(25)
        ->and($batch[24]->sole()->name)->toBe('Name 25')
        ->and($batchQueries)->toBe($singleQueries)->toBeLessThanOrEqual(2);

    $models[0]->setRelation('translations', $models[1]->getRelation('translations'));
    expect(fn () => $store->rowsForOwners($models))->toThrow(OwnershipViolation::class)
        ->and($store->rowsForOwners([]))->toBe([]);
});

test('it resolves progressively less-specific locale parents', function (): void {
    config()->set('nvl-translatable.locales', ['zh', 'zh-Hant', 'zh-Hant-TW']);
    config()->set('nvl-translatable.default_locale', 'zh');
    config()->set('nvl-translatable.fallback_locales', []);
    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
        locales: ['zh', 'zh-Hant', 'zh-Hant-TW'],
    );

    expect($definition->localeChain('zh-Hant-TW'))->toBe([
        'zh-Hant-TW',
        'zh-Hant',
        'zh',
    ]);
});

test('model locale overrides can only narrow the global locale catalog', function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg']);

    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
        locales: ['en', 'fr'],
    );

    expect(fn () => $definition->supportedLocales())
        ->toThrow(TranslatableException::class, 'Model locales must be a subset');
});

test('configured fallback locales must be supported by the model catalog', function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg']);

    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
        locales: ['en', 'bg'],
        fallbackLocales: ['fr'],
    );

    expect(fn () => $definition->configuredFallbackLocales())
        ->toThrow(TranslatableException::class, 'locale [fr] is not supported');
});

test('typed declarations reject structural collisions and malformed configuration', function (): void {
    expect(fn () => new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['locale'],
    ))->toThrow(TranslatableException::class, 'Structural column')
        ->and(fn () => new RelatedTranslationDefinition(
            translationModel: TestTranslatableModelTranslation::class,
            fields: ['owner_id'],
            foreignKey: 'owner_id',
        ))->toThrow(TranslatableException::class, 'Structural column')
        ->and(fn () => new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name'],
            sharedFields: ['locale'],
        ))->toThrow(TranslatableException::class, 'shared field')
        ->and(fn () => (new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['id'],
        ))->assertModel(new TestSelfTranslatableModel))
        ->toThrow(TranslatableException::class, 'primary key')
        ->and(fn () => (new RelatedTranslationDefinition(
            translationModel: TestTimestampedTranslation::class,
            fields: ['updated_at'],
        ))->assertModel(new TestTranslatableModel))
        ->toThrow(TranslatableException::class, 'Structural column')
        ->and(fn () => (new SelfTranslationDefinition(
            groupKey: 'entry_key',
            fields: ['name'],
            sharedFields: ['created_at'],
        ))->assertModel(new TestTimestampedTranslation))
        ->toThrow(TranslatableException::class, 'model-managed column');

    config()->set('nvl-translatable.locales', ['en', 42]);
    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
    );

    expect(fn () => $definition->supportedLocales())
        ->toThrow(TranslatableException::class, 'locale must be a string');
});

test('legacy related options preserve modern fallback and mutation policies', function (): void {
    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
        fallbackPolicy: TranslationFallbackPolicy::AnyAvailable,
        mutationPolicy: TranslationMutationPolicy::OwningServiceOnly,
    );
    $roundTripped = TranslatableOptions::fromDefinition($definition)->toDefinition();

    expect($roundTripped->fallbackPolicy)->toBe(TranslationFallbackPolicy::AnyAvailable)
        ->and($roundTripped->mutationPolicy)->toBe(TranslationMutationPolicy::OwningServiceOnly);
});

test('it resolves all fields from one admitted translation collection', function (): void {
    $owner = TestTranslatableModel::create(['slug' => 'one-collection']);
    app(TranslationWriter::class)->sync($owner, ['en' => ['name' => 'Name', 'description' => 'Description']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($owner));
    app(TranslationOwnership::class)->assertOwner($owner, $owner->translationDefinition());
    $owner->unsetRelation('translations');
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect($owner->getTranslatedAttributes('en'))->toBe(['name' => 'Name', 'description' => 'Description']);
        expect(DB::getQueryLog())->toHaveCount(4);
    } finally {
        DB::disableQueryLog();
    }
});
