<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\Locale;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslatableOptions;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationPayloadValidator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModelTranslation;
use Nvl\Translatable\TranslatableOptions;
use Nvl\Translatable\TranslationResourceDefinition;

test('configured resources fail explicitly for every malformed serializable option', function (
    mixed $resources,
    string $message,
): void {
    config()->set('translatable.resources', $resources);

    expect(fn () => (new TranslatableServiceProvider(app()))->boot(
        new TranslationResourceRegistry,
        app(TypeScriptSourceRegistry::class),
    ))->toThrow(TranslationResourceException::class, $message);
})->with([
    'resource catalog' => ['invalid', 'must be an array'],
    'entry key' => [[[null]], 'string key and array definition'],
    'unknown option' => [[
        'tests.invalid' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid',
            'hidden' => true,
        ],
    ], 'unknown options'],
    'missing model' => [[
        'tests.invalid' => ['label' => 'Invalid'],
    ], 'existing model class'],
    'order column' => [[
        'tests.invalid' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid',
            'order_column' => 1,
        ],
    ], 'order_column must be a string or null'],
    'maximum page size' => [[
        'tests.invalid' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid',
            'maximum_page_size' => '100',
        ],
    ], 'maximum_page_size must be an integer'],
    'searchable list' => [[
        'tests.invalid' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid',
            'searchable_columns' => 'slug',
        ],
    ], 'array of strings'],
    'display list value' => [[
        'tests.invalid' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Invalid',
            'display_columns' => [''],
        ],
    ], 'non-empty strings'],
]);

test('configured resources register the complete cache-safe metadata contract', function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('translatable.resources', [
        'consumer.articles' => [
            'model' => TestTranslatableModel::class,
            'label' => 'Consumer articles',
            'searchable_columns' => ['slug'],
            'display_columns' => ['slug'],
            'order_column' => 'id',
            'maximum_page_size' => 25,
        ],
    ]);
    $resources = new TranslationResourceRegistry;

    (new TranslatableServiceProvider(app()))->boot(
        $resources,
        app(TypeScriptSourceRegistry::class),
    );

    $resource = $resources->get('consumer.articles');

    expect($resource->maximumPageSize)->toBe(25)
        ->and($resource->searchableColumns)->toBe(['slug'])
        ->and($resource->displayColumns)->toBe(['slug'])
        ->and($resource->orderColumn)->toBe('id')
        ->and($resource->metadata(['bg']))->toMatchArray([
            'key' => 'consumer.articles',
            'storage' => 'related',
            'locales' => ['bg'],
        ]);
});

test('consumer commands support human and machine readable resource workflows', function (): void {
    config()->set([
        'translatable.locales' => ['en', 'bg', 'en-GB'],
        'translatable.fallback_locales' => ['en'],
    ]);
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
        $table->unique(['test_translatable_model_id', 'locale']);
    });
    app(TranslationResourceRegistry::class)->register(
        key: 'consumer.articles',
        modelClass: TestTranslatableModel::class,
        label: 'Consumer articles',
        searchableColumns: ['slug'],
        displayColumns: ['slug'],
        orderColumn: 'id',
        maximumPageSize: 25,
    );
    $article = TestTranslatableModel::create(['slug' => 'consumer-guide']);
    app(TranslationWriter::class)->patch($article, [
        'en' => ['name' => 'Consumer guide'],
        'bg' => ['name' => 'Потребителско ръководство'],
    ]);

    $this->artisan('nvl:translatable:gather')
        ->expectsOutputToContain('consumer.articles')
        ->assertSuccessful();
    $this->artisan('nvl:translatable:gather', [
        'resource' => 'consumer.articles',
        '--search' => ' consumer ',
        '--missing' => ' en-GB ',
        '--page' => 0,
        '--per-page' => 999,
        '--json' => true,
    ])
        ->expectsOutputToContain('"slug": "consumer-guide"')
        ->assertSuccessful();
    $this->artisan('nvl:translatable:gather', [
        'resource' => 'consumer.articles',
        '--search' => ' ',
        '--missing' => ' ',
    ])
        ->expectsOutputToContain('consumer-guide')
        ->expectsOutputToContain('Page 1 of 1 (1 records).')
        ->assertSuccessful();
});

test('doctor text output is actionable for healthy and invalid consumers', function (): void {
    $this->artisan('nvl:translatable:doctor')
        ->expectsOutputToContain('Translation diagnostics passed')
        ->assertSuccessful();

    config()->set('translatable.default_locale', 'fr');

    $this->artisan('nvl:translatable:doctor')
        ->expectsOutputToContain('translatable.default_locale')
        ->expectsOutputToContain('Translation diagnostics failed')
        ->assertFailed();
});

test('legacy option and locale helpers preserve the canonical consumer contract', function (): void {
    config()->set([
        'translatable.locales' => ['en', 'bg', 'en-GB'],
        'translatable.default_locale' => 'en',
        'translatable.fallback_locales' => ['en'],
    ]);
    $related = new TranslatableOptions(
        translationModel: TestTranslatableModelTranslation::class,
        foreignKey: '{table}_owner_id',
        translatableFields: ['name'],
        useFallback: false,
        availableLocales: ['en', 'bg'],
        fallbackLocale: 'bg',
        fallbackLocales: ['en', 'bg'],
        mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
    );
    $self = new SelfTranslatableOptions(
        localeKey: 'locale',
        groupKey: 'entry_key',
        useFallback: false,
        fallbackLocale: 'bg',
        availableLocales: ['en', 'bg'],
        translatableFields: ['name'],
        fallbackLocales: ['en', 'bg'],
        sharedFields: ['type'],
        allowDeletingLastTranslation: true,
        mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
    );
    $selfDefinition = $self->toDefinition();
    $roundTrippedSelf = SelfTranslatableOptions::fromDefinition($selfDefinition);

    expect($related->getForeignKey('news_items'))->toBe('news_items_owner_id')
        ->and($related->normalizedFallbackLocales())->toBe(['en', 'bg'])
        ->and($related->localeChain('bg'))->toBe(['bg'])
        ->and($related->configuredFallbackLocales())->toBe([])
        ->and($related->assertLocale('BG'))->toBe('bg')
        ->and($related->toDefinition()->fallbackPolicy)
        ->toBe(TranslationFallbackPolicy::ExactOnly)
        ->and($selfDefinition->fallbackPolicy)->toBe(TranslationFallbackPolicy::ExactOnly)
        ->and($roundTrippedSelf->groupKey)->toBe('entry_key')
        ->and($roundTrippedSelf->sharedFields)->toBe(['type'])
        ->and($roundTrippedSelf->allowDeletingLastTranslation)->toBeTrue()
        ->and(Locale::EN->internationalLabel())->toBe('English')
        ->and(Locale::BG->nativeLabel())->toBe('Български')
        ->and(Locale::normalizeLanguageCode(' BG '))->toBe('bg')
        ->and(Locale::normalizeLanguageCode('fr', 'bg'))->toBe('bg')
        ->and(Locale::fromValue('EN'))->toBe(Locale::EN)
        ->and(Locale::fromValue(null))->toBeNull()
        ->and(Locale::options(Locale::BG)[1]['active'])->toBeTrue();
});

test('related and self consumers can compose every public query and locale helper', function (): void {
    config()->set([
        'translatable.locales' => ['en', 'bg', 'en-GB'],
        'translatable.fallback_locales' => ['en'],
    ]);
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
        $table->unique(['test_translatable_model_id', 'locale']);
    });
    Schema::dropIfExists('test_self_translatable_models');
    Schema::create('test_self_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->string('entry_key');
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->nullable();
        $table->unique(['entry_key', 'locale']);
    });
    $writer = app(TranslationWriter::class);
    $alpha = TestTranslatableModel::create(['slug' => 'alpha']);
    $beta = TestTranslatableModel::create(['slug' => 'beta']);
    $writer->patch($alpha, [
        'en' => ['name' => 'Alpha', 'description' => null],
        'bg' => ['name' => 'Алфа', 'description' => 'Описание'],
    ]);
    $writer->upsert($beta, 'en', ['name' => 'Beta', 'description' => 'Description']);
    $self = TestSelfTranslatableModel::create([
        'entry_key' => 'consumer.alpha',
        'locale' => 'en',
        'name' => 'Alpha',
        'description' => null,
        'type' => 'consumer',
    ]);
    $self->setTranslation([
        'name' => 'Алфа',
        'description' => 'Описание',
    ], 'bg');
    app(ContentLocale::class)->set('bg');

    expect($alpha->setLocale('bg')->getCurrentLocale())->toBe('bg')
        ->and($alpha->translationOptions()->translatableFields)->toBe(['name', 'description'])
        ->and($alpha->isTranslatableAttribute('name'))->toBeTrue()
        ->and($alpha->getTranslatedAttributes('bg')['name'])->toBe('Алфа')
        ->and($alpha->getAllTranslations())->toHaveCount(2)
        ->and($alpha->hasTranslation('bg'))->toBeTrue()
        ->and(TestTranslatableModel::query()->whereTranslationNull('description', 'en')->count())->toBe(1)
        ->and(TestTranslatableModel::query()->whereTranslationNotNull('description', 'en')->count())->toBe(1)
        ->and(TestTranslatableModel::query()->orderByTranslated('name', 'asc', 'en')->pluck('slug')->all())
        ->toBe(['alpha', 'beta'])
        ->and($self->setLocale('bg')->getCurrentLocale())->toBe('bg')
        ->and($self->translationOptions()->sharedFields)->toBe(['type'])
        ->and($self->getLocaleKey())->toBe('locale')
        ->and($self->getTranslation('bg', withFallback: false)?->name)->toBe('Алфа')
        ->and($self->getTranslatedAttributes('bg')['description'])->toBe('Описание')
        ->and($self->isTranslatableAttribute('name'))->toBeTrue()
        ->and($self->hasTranslation('bg'))->toBeTrue()
        ->and($self->hasTranslation('en-GB'))->toBeFalse()
        ->and(TestSelfTranslatableModel::query()->whereTranslated('name', '=', 'Алфа', 'bg')->count())->toBe(1)
        ->and(TestSelfTranslatableModel::query()->whereTranslationNull('description', 'en')->count())->toBe(1)
        ->and(TestSelfTranslatableModel::query()->whereTranslationNotNull('description', 'bg')->count())->toBe(1)
        ->and(TestSelfTranslatableModel::query()->orderByTranslated('name', 'asc', 'bg')->first()?->name)
        ->toBe('Алфа');
});

test('locale registry rejects malformed catalogs and builds deterministic regional chains', function (): void {
    $registry = app(LocaleRegistry::class);

    config()->set('translatable.locales', []);
    expect(fn () => $registry->supported())->toThrow(TranslatableException::class, 'at least one locale');

    config()->set('translatable.locales', ['en', 42]);
    expect(fn () => $registry->supported())->toThrow(TranslatableException::class, 'must be a string');

    config()->set('translatable.locales', ['en', 'EN']);
    expect(fn () => $registry->supported())->toThrow(TranslatableException::class, 'Duplicate normalized');

    config()->set([
        'translatable.locales' => ['en', 'en-GB', 'bg'],
        'translatable.default_locale' => 'en',
        'translatable.fallback_locales' => ['bg'],
    ]);
    expect($registry->supported())->toBe(['en', 'en-GB', 'bg'])
        ->and($registry->fallbacks())->toBe(['bg'])
        ->and($registry->default())->toBe('en')
        ->and($registry->supports('EN-gb'))->toBeTrue()
        ->and($registry->supports('bad locale'))->toBeFalse()
        ->and($registry->chain('en-GB', ['bg']))->toBe(['en-GB', 'en', 'bg']);

    config()->set('translatable.fallback_locales', 'bg');
    expect(fn () => $registry->fallbacks())->toThrow(TranslatableException::class, 'must be an array');

    config()->set('translatable.fallback_locales', [42]);
    expect(fn () => $registry->fallbacks())->toThrow(TranslatableException::class, 'must be a string');

    config()->set('translatable.fallback_locales', ['bg', 'BG']);
    expect(fn () => $registry->fallbacks())->toThrow(TranslatableException::class, 'Duplicate normalized');

    config()->set([
        'translatable.fallback_locales' => [],
        'translatable.default_locale' => 42,
    ]);
    expect(fn () => $registry->default())->toThrow(TranslatableException::class, 'must be a string');

    config()->set('translatable.default_locale', 'en');
    expect(fn () => $registry->assertSupported('fr'))->toThrow(TranslatableException::class, 'not supported')
        ->and(fn () => $registry->chain('en', [42]))
        ->toThrow(TranslatableException::class, 'additional translation fallback locale');
});

test('payload validation rejects every unsafe consumer shape before persistence', function (): void {
    config()->set([
        'translatable.locales' => ['en', 'bg'],
        'translatable.limits.mutation_locales' => 1,
        'translatable.limits.mutation_fields' => 1,
        'translatable.limits.mutation_value_bytes' => 30,
        'translatable.limits.mutation_depth' => 4,
    ]);
    $validator = app(TranslationPayloadValidator::class);
    $definition = new RelatedTranslationDefinition(
        translationModel: TestTranslatableModelTranslation::class,
        fields: ['name'],
    );

    expect(fn () => $validator->validate($definition, [
        'en' => ['name' => 'English'],
        'bg' => ['name' => 'Bulgarian'],
    ]))->toThrow(TranslatableException::class, 'at most 1 locales')
        ->and(fn () => $validator->validate($definition, [0 => ['name' => 'English']]))
        ->toThrow(TranslatableException::class, 'string locale keys')
        ->and(fn () => $validator->validate($definition, ['en' => 'English']))
        ->toThrow(TranslatableException::class, 'field-keyed array')
        ->and(fn () => $validator->validate($definition, [
            'en' => ['name' => 'English', 'other' => 'Other'],
        ]))->toThrow(TranslatableException::class, 'at most 1 fields')
        ->and(fn () => $validator->validate($definition, ['en' => [0 => 'English']]))
        ->toThrow(TranslatableException::class, 'string field keys')
        ->and(fn () => $validator->validate($definition, [
            'en' => ['name' => str_repeat('x', 40)],
        ]))->toThrow(TranslatableException::class, 'encoded bytes');

    $stream = fopen('php://memory', 'r');

    try {
        expect(fn () => $validator->validate($definition, ['en' => ['name' => $stream]]))
            ->toThrow(TranslatableException::class, 'JSON-serializable');
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    config()->set('translatable.limits.mutation_fields', 0);
    expect(fn () => $validator->validate($definition, []))
        ->toThrow(TranslatableException::class, 'positive integer');
});

test('resource definitions reject unsafe public metadata and expose authorization decisions', function (
    Closure $factory,
    string $message,
): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);

    expect($factory)->toThrow(TranslationResourceException::class, $message);
})->with([
    'key' => [fn () => new TranslationResourceDefinition(
        key: 'Consumer Articles',
        label: 'Articles',
        modelClass: TestTranslatableModel::class,
    ), 'lowercase dot'],
    'label' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: ' ',
        modelClass: TestTranslatableModel::class,
    ), 'requires a label'],
    'page size' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: TestTranslatableModel::class,
        maximumPageSize: 501,
    ), 'between 1 and 500'],
    'unknown model' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: 'Consumer\\MissingModel',
    ), 'does not exist'],
    'unsupported model' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: TestTranslatableModelTranslation::class,
    ), 'must implement TranslatableResourceModel'],
    'unsafe column' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: TestTranslatableModel::class,
        searchableColumns: ['slug;drop'],
    ), 'unsafe searchable column'],
    'non-string column' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: TestTranslatableModel::class,
        displayColumns: [[]],
    ), 'unsafe display column'],
    'duplicate column' => [fn () => new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: TestTranslatableModel::class,
        searchableColumns: ['slug', 'SLUG'],
    ), 'duplicate searchable column'],
]);

test('resource authorization and default metadata remain transport neutral', function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    $resource = new TranslationResourceDefinition(
        key: 'consumer.articles',
        label: 'Articles',
        modelClass: TestTranslatableModel::class,
        authorization: static fn (
            TranslationActorData $actor,
            TranslationResourceAbility $ability,
            ?Model $record,
        ): bool => $actor->system
            && $ability === TranslationResourceAbility::View
            && $record === null,
    );

    expect($resource->authorize(
        TranslationActorData::system('test'),
        TranslationResourceAbility::View,
        null,
    ))->toBeTrue()
        ->and($resource->metadata([])['locales'])->toBe(['en', 'bg', 'en-GB']);
});

test('doctor reports malformed global configuration without throwing', function (): void {
    $doctor = app(TranslationDoctor::class);
    $invalidConfigurations = [
        ['translatable.locales' => []],
        ['translatable.locales' => ['en', 42]],
        ['translatable.locales' => ['en', 'bad locale']],
        ['translatable.locales' => ['en', 'EN']],
        ['translatable.default_locale' => 'fr'],
        ['translatable.fallback_locales' => 'en'],
        ['translatable.fallback_locales' => ['fr']],
        ['translatable.fallback_locales' => ['en', 'EN']],
        ['translatable.fallback.policy' => 'random'],
        ['translatable.fallback.on_null' => 'yes'],
        ['translatable.limits.mutation_fields' => 0],
        ['translatable.middleware.query_parameter' => ''],
        ['translatable.middleware.cookie_minutes' => 0],
    ];

    foreach ($invalidConfigurations as $configuration) {
        config()->set('translatable', require __DIR__.'/../../config/translatable.php');
        config()->set($configuration);

        expect($doctor->inspect()->isHealthy())->toBeFalse();
    }

    config()->set('translatable.locales', 'en');
    config()->set('translatable.labels', 'English');

    expect($doctor->inspect()->warnings)->toBe([]);
});

test('doctor gives exact migration guidance for broken related schemas', function (): void {
    config()->set([
        'translatable.locales' => ['en', 'bg', 'en-GB'],
        'translatable.default_locale' => 'en',
        'translatable.fallback_locales' => ['en'],
    ]);
    Schema::dropIfExists('test_translatable_models_i18n');
    Schema::dropIfExists('test_translatable_models');
    app(TranslationResourceRegistry::class)->register(
        key: 'doctor.articles',
        modelClass: TestTranslatableModel::class,
        label: 'Doctor articles',
        searchableColumns: ['slug'],
    );
    $doctor = app(TranslationDoctor::class);

    expect(implode(' ', $doctor->inspect()->errors))->toContain('missing table [test_translatable_models]');

    Schema::create('test_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    expect(implode(' ', $doctor->inspect()->errors))
        ->toContain('lacks column [slug]')
        ->toContain('missing translation table [test_translatable_models_i18n]');

    Schema::create('test_translatable_models_i18n', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('test_translatable_model_id')->nullable();
        $table->string('locale', 10)->nullable();
        $table->string('name')->nullable();
    });
    $broken = implode(' ', $doctor->inspect()->errors);

    expect($broken)
        ->toContain('lacks column [description]')
        ->toContain('cannot be nullable')
        ->toContain('requires a unique index')
        ->toContain('lacks its owner foreign key');

    Schema::drop('test_translatable_models_i18n');
    Schema::create('test_translatable_models_i18n', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('test_translatable_model_id')
            ->constrained('test_translatable_models');
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->unique(['test_translatable_model_id', 'locale']);
    });

    expect(implode(' ', $doctor->inspect()->errors))
        ->toContain('owner foreign key must cascade on delete');
});
