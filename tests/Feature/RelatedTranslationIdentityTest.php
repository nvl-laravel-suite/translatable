<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\RelatedIdentitySchema;
use Nvl\Translatable\Tests\Support\SoftDeletingRelatedOwner;
use Nvl\Translatable\Tests\Support\SoftDeletingRelatedTranslation;

beforeEach(function (): void {
    RelatedIdentitySchema::create(Schema::getFacadeRoot(), uniqueIndex: false);
    $this->owner = SoftDeletingRelatedOwner::query()->create(['slug' => 'identity']);
});

afterEach(function (): void {
    RelatedIdentitySchema::drop(Schema::getFacadeRoot());
});

it('rejects several active rows for one owner and locale', function (bool $restore): void {
    config()->set('nvl-translatable.test_restore_deleted', $restore);
    $rows = DB::table('related_identity_translations');
    $rows->insert([
        ['related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'First'],
        ['related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'Second'],
    ]);
    $store = app(RelatedTranslationStore::class);

    expect(fn () => DB::transaction(fn () => $store->upsert($this->owner, $this->owner->translationDefinition(), 'bg', ['name' => 'Third'])))
        ->toThrow(TranslatableException::class, 'Several active translation rows')
        ->and(DB::table('related_identity_translations')->orderBy('id')->pluck('name')->all())->toBe(['First', 'Second']);
})->with(['restore' => true, 'reject' => false]);

it('rejects several soft-deleted rows for one owner and locale', function (bool $restore, string $message): void {
    config()->set('nvl-translatable.test_restore_deleted', $restore);
    DB::table('related_identity_translations')->insert([
        ['related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'First', 'deleted_at' => now()],
        ['related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'Second', 'deleted_at' => now()],
    ]);
    $store = app(RelatedTranslationStore::class);

    expect(fn () => DB::transaction(fn () => $store->upsert($this->owner, $this->owner->translationDefinition(), 'bg', ['name' => 'Third'])))
        ->toThrow(TranslatableException::class, $message)
        ->and(SoftDeletingRelatedTranslation::query()->count())->toBe(0)
        ->and(SoftDeletingRelatedTranslation::onlyTrashed()->count())->toBe(2);
})->with([
    'restore' => [true, 'Several soft-deleted translation rows'],
    'reject' => [false, 'A soft-deleted translation row blocks'],
]);

it('rolls back the whole locale batch when a restore is interrupted', function (): void {
    config()->set('nvl-translatable.test_restore_deleted', true);
    $deleted = SoftDeletingRelatedTranslation::query()->forceCreate([
        'related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'Old', 'deleted_at' => now(),
    ]);
    SoftDeletingRelatedTranslation::restoring(static fn (): bool => false);
    $store = app(RelatedTranslationStore::class);

    expect(fn () => DB::transaction(fn () => $store->upsertMany($this->owner, $this->owner->translationDefinition(), [
        'en' => ['name' => 'English'],
        'bg' => ['name' => 'Restored'],
    ])))->toThrow(TranslatableException::class, 'Restoring the soft-deleted translation row was interrupted')
        ->and(SoftDeletingRelatedTranslation::query()->count())->toBe(0)
        ->and(SoftDeletingRelatedTranslation::onlyTrashed()->sole()->only(['id', 'name']))->toBe(['id' => $deleted->id, 'name' => 'Old']);
});

it('keeps a soft-deleted row as history and adds a fresh active row under KeepHistory', function (): void {
    config()->set('nvl-translatable.test_deleted_row_policy', 'keep_history');
    $deleted = SoftDeletingRelatedTranslation::query()->forceCreate([
        'related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'Old', 'deleted_at' => now(),
    ]);
    $store = app(RelatedTranslationStore::class);

    DB::transaction(fn () => $store->upsert($this->owner, $this->owner->translationDefinition(), 'bg', ['name' => 'New']));
    DB::transaction(fn () => $store->upsert($this->owner, $this->owner->translationDefinition(), 'bg', ['name' => 'Newer']));

    expect(SoftDeletingRelatedTranslation::query()->pluck('name')->all())->toBe(['Newer'])
        ->and(SoftDeletingRelatedTranslation::query()->sole()->getKey())->not->toBe($deleted->getKey())
        ->and(SoftDeletingRelatedTranslation::onlyTrashed()->sole()->getKey())->toBe($deleted->getKey());
});

it('recreates a deleted KeepHistory locale only once the complete unique index is gone', function (bool $completeIndex): void {
    config()->set('nvl-translatable.test_deleted_row_policy', 'keep_history');
    DB::statement('CREATE UNIQUE INDEX history_active ON related_identity_translations (related_identity_owner_id, locale) WHERE deleted_at IS NULL');
    if ($completeIndex) {
        DB::statement('CREATE UNIQUE INDEX history_complete ON related_identity_translations (related_identity_owner_id, locale)');
    }
    app(TranslationResourceRegistry::class)->register('tests.history', SoftDeletingRelatedOwner::class, 'History models');
    $store = app(RelatedTranslationStore::class);
    $definition = $this->owner->translationDefinition();
    $original = DB::transaction(fn () => $store->upsert($this->owner, $definition, 'bg', ['name' => 'Original']));
    DB::transaction(fn () => $store->delete($this->owner, $definition, 'bg'));
    $doctorErrors = implode("\n", app(TranslationDoctor::class)->inspect()->errors);
    $recreate = fn () => DB::transaction(fn () => $store->upsert($this->owner, $definition, 'bg', ['name' => 'Recreated']));

    if ($completeIndex) {
        expect($doctorErrors)->toContain('keeps the complete unique index [history_complete]')
            ->and($recreate)->toThrow(TranslatableException::class, 'A soft-deleted translation row blocks')
            ->and(SoftDeletingRelatedTranslation::query()->count())->toBe(0);
    } else {
        expect($doctorErrors)->not->toContain('Resource [tests.history]')
            ->and($recreate()->getKey())->not->toBe($original->getKey())
            ->and(SoftDeletingRelatedTranslation::query()->sole()->getAttribute('name'))->toBe('Recreated');
    }
    expect(SoftDeletingRelatedTranslation::onlyTrashed()->sole()->getKey())->toBe($original->getKey());
})->with(['active-only index' => false, 'active-only and complete index' => true]);

it('rolls back the entire batch when a later model save is vetoed', function (bool $existing): void {
    Event::fake([TranslationResourceSynced::class]);
    app(TranslationResourceRegistry::class)->register('tests.history', SoftDeletingRelatedOwner::class, 'History models');
    if ($existing) {
        SoftDeletingRelatedTranslation::query()->forceCreate(['related_identity_owner_id' => $this->owner->id, 'locale' => 'bg', 'name' => 'Old']);
    }
    SoftDeletingRelatedTranslation::saving(static fn (SoftDeletingRelatedTranslation $row): ?bool => $row->getAttribute('locale') === 'bg' ? false : null);

    expect(fn () => app(TranslationWriter::class)->sync(
        $this->owner,
        ['en' => ['name' => 'English'], 'bg' => ['name' => 'Vetoed']],
        SystemActor::Background,
        app(TranslationResourceVersioner::class)->version($this->owner),
    ))->toThrow(TranslatableException::class, 'A model observer interrupted saving the translation row.')
        ->and(SoftDeletingRelatedTranslation::query()->orderBy('locale')->pluck('name', 'locale')->all())->toBe($existing ? ['bg' => 'Old'] : []);
    Event::assertNotDispatched(TranslationResourceSynced::class);
})->with(['created row' => false, 'updated row' => true]);

it('reports a complete unique index left enforcing but invalid by a failed concurrent build', function (): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Only PostgreSQL keeps a ready but invalid index after a cancelled concurrent build.');
    }
    config()->set('nvl-translatable.test_deleted_row_policy', 'keep_history');
    DB::statement('CREATE UNIQUE INDEX history_active ON related_identity_translations (related_identity_owner_id, locale) WHERE deleted_at IS NULL');
    app(TranslationResourceRegistry::class)->register('tests.history', SoftDeletingRelatedOwner::class, 'History models');
    $store = app(RelatedTranslationStore::class);
    $definition = $this->owner->translationDefinition();
    DB::transaction(fn () => $store->upsert($this->owner, $definition, 'bg', ['name' => 'Original']));
    DB::transaction(fn () => $store->delete($this->owner, $definition, 'bg'));

    $source = $connection->getConfig();
    unset($source['name']);
    config()->set('database.connections.history_snapshot', $source);
    $snapshot = DB::connection('history_snapshot');
    $snapshot->statement('begin isolation level repeatable read');
    $snapshot->select('select 1');
    try {
        $connection->statement("set statement_timeout = '1500ms'");
        expect(fn () => $connection->statement('CREATE UNIQUE INDEX CONCURRENTLY history_complete ON related_identity_translations (related_identity_owner_id, locale)'))
            ->toThrow(QueryException::class, 'statement timeout');
    } finally {
        $connection->statement('set statement_timeout = 0');
        $snapshot->statement('rollback');
        $snapshot->disconnect();
    }
    expect((array) $connection->selectOne("select indisready, indisvalid from pg_index where indexrelid = 'history_complete'::regclass"))
        ->toBe(['indisready' => true, 'indisvalid' => false])
        ->and(implode("\n", app(TranslationDoctor::class)->inspect()->errors))->toContain('keeps the complete unique index [history_complete]')
        ->and(fn () => DB::transaction(fn () => $store->upsert($this->owner, $definition, 'bg', ['name' => 'Recreated'])))
        ->toThrow(TranslatableException::class, 'A soft-deleted translation row blocks');
});
