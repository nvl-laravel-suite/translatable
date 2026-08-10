<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTimestampedTranslation;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModelTranslation;
use Nvl\Translatable\TranslatableOptions;

beforeEach(function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('translatable.fallback_locales', ['en']);

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
});

test('it writes and explicitly resolves translated attributes', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->upsert($model, 'en', ['name' => 'English Name']);
    $writer->upsert($model, 'bg', ['name' => 'Bulgarian Name']);

    expect($model->translated('name', 'en'))->toBe('English Name')
        ->and($model->translated('name', 'bg'))->toBe('Bulgarian Name')
        ->and($model->getAttribute('name'))->toBeNull();
});

test('it resolves missing rows and null fields through deterministic fallback', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->upsert($model, 'en', [
        'name' => 'English Name',
        'description' => 'English description',
    ]);
    $writer->upsert($model, 'bg', [
        'name' => 'Bulgarian Name',
        'description' => null,
    ]);

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

    $writer->upsert($model, 'en', ['name' => 'Base English']);
    $writer->upsert($model, 'bg', ['name' => 'Configured fallback']);

    config()->set('translatable.fallback_locales', ['bg']);

    $resolution = $model->resolveTranslation('name', 'en-GB');

    expect($resolution->value)->toBe('Base English')
        ->and($resolution->resolvedLocale)->toBe('en');
});

test('it uses a deterministic persisted locale when any-available fallback is explicit', function (): void {
    config()->set('translatable.default_locale', 'en');
    config()->set('translatable.fallback_locales', []);
    config()->set(
        'translatable.fallback.policy',
        TranslationFallbackPolicy::AnyAvailable->value,
    );

    $model = TestTranslatableModel::create(['slug' => 'first-available']);
    $writer = app(TranslationWriter::class);

    $writer->upsert($model, 'bg', ['name' => 'Bulgarian']);

    $resolution = $model->resolveTranslation('name', 'en-GB');

    expect($resolution->value)->toBe('Bulgarian')
        ->and($resolution->resolvedLocale)->toBe('bg');
});

test('it never falls back when exact-only resolution is configured', function (): void {
    config()->set(
        'translatable.fallback.policy',
        TranslationFallbackPolicy::ExactOnly->value,
    );
    $model = TestTranslatableModel::create(['slug' => 'exact-only']);
    $writer = app(TranslationWriter::class);

    $writer->upsert($model, 'en', ['name' => 'English']);

    expect($model->resolveTranslation('name', 'bg')->isMissing())->toBeTrue()
        ->and($model->getTranslation('bg', withFallback: false))->toBeNull();
});

test('it treats an empty string as an intentional translated value', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->upsert($model, 'en', ['description' => 'Fallback description']);
    $writer->upsert($model, 'bg', ['description' => '']);

    $resolution = $model->resolveTranslation('description', 'bg');

    expect($resolution->value)->toBe('')
        ->and($resolution->resolvedLocale)->toBe('bg')
        ->and($resolution->usedFallback())->toBeFalse();
});

test('it uses request-scoped content locale when no locale is passed', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->patch($model, [
        'en' => ['name' => 'English Name'],
        'bg' => ['name' => 'Bulgarian Name'],
    ]);

    app(ContentLocale::class)->set('bg');

    expect($model->translated('name'))->toBe('Bulgarian Name');
});

test('it falls back when the request locale is unavailable for the model', function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB', 'fr']);
    app(ContentLocale::class)->set('fr');
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    app(TranslationWriter::class)->upsert($model, 'en', ['name' => 'English Name']);

    expect($model->getCurrentLocale())->toBe('en')
        ->and($model->translated('name'))->toBe('English Name');
});

test('it patches and replaces locale sets explicitly', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'test-slug']);
    $writer = app(TranslationWriter::class);

    $writer->patch($model, [
        'en' => ['name' => 'English Name'],
        'bg' => ['name' => 'Bulgarian Name'],
    ]);
    $writer->patch($model, [
        'en' => ['description' => 'English description'],
    ]);

    expect($model->getAvailableLocales())->toEqualCanonicalizing(['en', 'bg'])
        ->and($model->translated('name', 'en'))->toBe('English Name');

    $writer->replace($model, [
        'bg' => ['name' => 'Само български'],
    ]);

    expect($model->getAvailableLocales())->toBe(['bg'])
        ->and($model->translated('name', 'bg'))->toBe('Само български');
});

test('it returns normalized available locales in deterministic order', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'locale-order']);
    $writer = app(TranslationWriter::class);

    $writer->patch($model, [
        'en-GB' => ['name' => 'British'],
        'en' => ['name' => 'English'],
        'bg' => ['name' => 'Bulgarian'],
    ]);
    $model->translations()->create([
        'locale' => 'EN',
        'name' => 'Non-canonical legacy row',
    ]);

    expect($model->getAvailableLocales())->toBe(['bg', 'en', 'en-GB']);
});

test('it scopes queries by a validated translated attribute', function (): void {
    $first = TestTranslatableModel::create(['slug' => 'slug-1']);
    $second = TestTranslatableModel::create(['slug' => 'slug-2']);
    $writer = app(TranslationWriter::class);

    $writer->upsert($first, 'en', ['name' => 'Apple']);
    $writer->upsert($second, 'bg', ['name' => 'Ябълка']);

    $results = TestTranslatableModel::whereTranslated('name', 'Apple', locale: 'en')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->getKey())->toBe($first->getKey());
});

test('it exposes unambiguous null and non-null translated field scopes', function (): void {
    $nullDescription = TestTranslatableModel::create(['slug' => 'null-description']);
    $withDescription = TestTranslatableModel::create(['slug' => 'with-description']);
    $writer = app(TranslationWriter::class);
    $writer->upsert($nullDescription, 'en', ['name' => 'Null', 'description' => null]);
    $writer->upsert($withDescription, 'en', ['name' => 'Present', 'description' => 'Value']);

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

    expect(fn () => $writer->patch($model, [
        'en' => ['name' => 'Valid'],
        'bg' => ['slug' => 'Not translatable'],
    ]))->toThrow(InvalidTranslatableFieldException::class)
        ->and($model->translations()->count())->toBe(0)
        ->and(fn () => $writer->upsert(
            new TestTranslatableModel,
            'en',
            ['name' => 'Unsaved'],
        ))->toThrow(TranslatableException::class, 'persisted owner');
});

test('it rejects malformed write-map shapes before changing translation rows', function (): void {
    $model = TestTranslatableModel::create(['slug' => 'invalid-shape']);
    $writer = app(TranslationWriter::class);

    expect(fn () => $writer->patch($model, [
        0 => ['name' => 'Missing locale key'],
    ]))->toThrow(TranslatableException::class, 'string locale keys')
        ->and(fn () => $writer->patch($model, [
            'en' => 'not-an-attribute-map',
        ]))->toThrow(TranslatableException::class, 'field-keyed array')
        ->and($model->translations()->count())->toBe(0);
});

test('it eager loads requested and fallback translations without per-model queries', function (): void {
    $writer = app(TranslationWriter::class);
    $create = static function (int $index) use ($writer): void {
        $model = TestTranslatableModel::create(['slug' => "slug-{$index}"]);
        $writer->upsert($model, 'en', ['name' => "Name {$index}"]);
    };
    $measure = static function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $models = TestTranslatableModel::withResolvedTranslations('bg')->get();
        $queryCount = count(DB::getQueryLog());
        $names = $models->map(
            static fn (TestTranslatableModel $model): mixed => $model->translated('name', 'bg'),
        );

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return [$queryCount, $names->all()];
    };

    $create(1);
    [$singleQueryCount] = $measure();

    foreach (range(2, 25) as $index) {
        $create($index);
    }

    [$populatedQueryCount, $names] = $measure();

    expect($names)->toHaveCount(25)
        ->and($names[0])->toBe('Name 1')
        ->and($singleQueryCount)->toBeLessThanOrEqual(2)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

test('it resolves progressively less-specific locale parents', function (): void {
    config()->set('translatable.locales', ['zh', 'zh-Hant', 'zh-Hant-TW']);
    config()->set('translatable.default_locale', 'zh');
    config()->set('translatable.fallback_locales', []);
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
    config()->set('translatable.locales', ['en', 'bg']);

    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
        locales: ['en', 'fr'],
    );

    expect(fn () => $definition->supportedLocales())
        ->toThrow(TranslatableException::class, 'Model locales must be a subset');
});

test('configured fallback locales must be supported by the model catalog', function (): void {
    config()->set('translatable.locales', ['en', 'bg']);

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

    config()->set('translatable.locales', ['en', 42]);
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
        mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
    );
    $roundTripped = TranslatableOptions::fromDefinition($definition)->toDefinition();

    expect($roundTripped->fallbackPolicy)->toBe(TranslationFallbackPolicy::AnyAvailable)
        ->and($roundTripped->mutationPolicy)->toBe(TranslationMutationPolicy::DomainActionOnly);
});
