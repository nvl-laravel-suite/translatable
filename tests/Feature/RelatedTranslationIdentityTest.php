<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TestCase');
}

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Services\RelatedTranslationStore;
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
