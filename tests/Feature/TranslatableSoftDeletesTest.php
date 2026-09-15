<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TestSoftDeletingSelfTranslatableModel;
use Nvl\Translatable\TranslationResourceQuery;

beforeEach(function (): void {
    config()->set('translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('translatable.fallback_locales', ['en']);

    Schema::dropIfExists('test_soft_deleting_self_translatable_models');
    Schema::create('test_soft_deleting_self_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->string('entry_key');
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->nullable();
        $table->softDeletes('archived_at');
        $table->unique(['entry_key', 'locale'], 'soft_self_translation_unique');
    });

    app(TranslationResourceRegistry::class)->register(
        key: 'tests.soft-self',
        modelClass: TestSoftDeletingSelfTranslatableModel::class,
        label: 'Soft-deletable self translations',
        searchableColumns: ['name'],
        displayColumns: ['entry_key'],
    );
});

test('locale queries fall back after the requested self translation is soft deleted', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('bg');

    expect(TestSoftDeletingSelfTranslatableModel::query()->locale('bg')->pluck('name')->all())
        ->toBe(['English'])
        ->and($model->getAvailableLocales())->toBe(['en'])
        ->and($model->translated('name', 'bg'))->toBe('English');
});

test('locale queries can explicitly include soft deleted preferred rows', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('bg');

    expect(TestSoftDeletingSelfTranslatableModel::withTrashed()->locale('bg')->pluck('name')->all())
        ->toBe(['Bulgarian']);
});

test('locale queries restricted to deleted rows ignore active preferred locales', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('en');

    expect(TestSoftDeletingSelfTranslatableModel::onlyTrashed()->locale('bg')->pluck('name')->all())
        ->toBe(['English']);
});

test('locale selection can run inside a model global scope without recursion', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('bg');
    $invocations = 0;
    TestSoftDeletingSelfTranslatableModel::addGlobalScope(
        'content_locale',
        function (Builder $query) use (&$invocations): void {
            if (++$invocations > 2) {
                throw new RuntimeException('Locale selection recursively reapplied its global scope.');
            }

            $query->locale('bg');
        },
    );

    try {
        expect(TestSoftDeletingSelfTranslatableModel::query()->pluck('name')->all())
            ->toBe(['English']);
    } finally {
        TestSoftDeletingSelfTranslatableModel::clearBootedModels();
    }
});

test('writing a deleted self translation restores its identity and retained content', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English', 'type' => 'original',
    ]);
    $bulgarian = $model->setTranslation(['name' => 'Bulgarian', 'description' => 'Retained'], 'bg');
    $model->deleteTranslation('bg');
    $model->setRelation('translations', $model->getAllTranslations());
    $model->forceFill(['type' => 'updated'])->save();

    $restored = $model->setTranslation(['name' => 'Bulgarian again'], 'bg');

    expect($restored->getKey())->toBe($bulgarian->getKey())
        ->and($restored->getAttribute('archived_at'))->toBeNull()
        ->and($restored->getAttribute('name'))->toBe('Bulgarian again')
        ->and($restored->getAttribute('description'))->toBe('Retained')
        ->and($restored->getAttribute('type'))->toBe('updated')
        ->and($model->getAvailableLocales())->toBe(['bg', 'en'])
        ->and(TestSoftDeletingSelfTranslatableModel::withTrashed()->count())->toBe(2);
});

test('replace restores a deleted locale before soft deleting the excluded locale', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $bulgarian = $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('bg');

    $model->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->replace($model, ['bg' => ['name' => 'Only Bulgarian']]),
    );

    expect(TestSoftDeletingSelfTranslatableModel::query()->pluck('id')->all())->toBe([$bulgarian->getKey()])
        ->and(TestSoftDeletingSelfTranslatableModel::withTrashed()->count())->toBe(2)
        ->and(TestSoftDeletingSelfTranslatableModel::query()->locale('en')->exists())->toBeFalse();
});

test('soft deleted locales do not count toward final locale protection', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('bg');

    expect(fn () => $model->deleteTranslation('en'))
        ->toThrow(TranslatableException::class, 'final locale row')
        ->and(TestSoftDeletingSelfTranslatableModel::query()->count())->toBe(1);
});

test('central reads retain active resources and omit deleted locales from coverage', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'en', 'name' => 'English',
    ]);
    $model->setTranslation(['name' => 'Bulgarian'], 'bg');
    $model->deleteTranslation('bg');
    $gatherer = app(TranslationResourceGatherer::class);
    $actor = TranslationActorData::system('test');

    $page = $gatherer->gather('tests.soft-self', $actor);
    $summary = $gatherer->summaries($actor)[0];

    expect($page->total())->toBe(1)
        ->and($summary->total)->toBe(1)
        ->and($summary->coverage['en']->translated)->toBe(1)
        ->and($summary->coverage['bg']->translated)->toBe(0)
        ->and($summary->coverage['bg']->missing)->toBe(1);

    $record = $gatherer->find('tests.soft-self', 'guide', $actor);
    $updated = app(SyncTranslationResourceAction::class)->execute(
        'tests.soft-self',
        'guide',
        new TranslationMutationData(
            translations: ['bg' => ['name' => 'Restored']],
            expectedVersion: $record->version,
        ),
        $actor,
    );

    expect($updated->id)->toBe('guide')
        ->and($gatherer->find('tests.soft-self', 'guide', $actor)->translations['bg']['name'])
        ->toBe('Restored');
});

test('central search does not match soft deleted translated content', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'bg', 'name' => 'Bulgarian',
    ]);
    $model->setTranslation(['name' => 'Archived English'], 'en');
    $model->deleteTranslation('en');

    $page = app(TranslationResourceGatherer::class)->gather(
        'tests.soft-self',
        TranslationActorData::system('test'),
        new TranslationResourceQuery(search: 'Archived'),
    );

    expect($page->total())->toBe(0);
});

test('central missing locale filters include soft deleted translations', function (): void {
    $model = TestSoftDeletingSelfTranslatableModel::create([
        'entry_key' => 'guide', 'locale' => 'bg', 'name' => 'Bulgarian',
    ]);
    $model->setTranslation(['name' => 'English'], 'en');
    $model->deleteTranslation('en');

    $page = app(TranslationResourceGatherer::class)->gather(
        'tests.soft-self',
        TranslationActorData::system('test'),
        new TranslationResourceQuery(missingLocale: 'en'),
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe('guide');
});
