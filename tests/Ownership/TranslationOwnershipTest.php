<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\OwnershipTestCase;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\OwnershipCompany;
use Nvl\Translatable\Tests\Support\OwnershipRelatedRecord;
use Nvl\Translatable\Tests\Support\OwnershipRelatedTranslation;
use Nvl\Translatable\Tests\Support\OwnershipSelfRecord;
use Nvl\Translatable\Tests\Support\OwnershipTranslationListener;

uses(OwnershipTestCase::class);

it('keeps related translations in their persisted native owner partition', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    foreach ([7, 8] as $id) {
        $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id): void {
            $record = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $assignment): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$assignment->attributes(), 'id' => $id * 10]));
            app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'Owner '.$id]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record));
            expect($record->translated('name', 'en'))->toBe('Owner '.$id)
                ->and(OwnershipRelatedTranslation::query()->count())->toBe(1);
        });
    }
});

it('revalidates batch rows and canonical owners after leaving their admitted scope', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $records = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function (): array {
        $records = [];
        foreach ([70, 71] as $id) {
            $record = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $assignment): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$assignment->attributes(), 'id' => $id]));
            $record = app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'Record '.$id]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record));
            $record->load('translations');
            $records[] = $record;
        }
        $store = app(RelatedTranslationStore::class);
        expect($store->rowsForOwners($records)[0]->sole()->getAttribute('name'))->toBe('Record 70');
        $forged = clone $records[0];
        $forged->setRelation('translations', $records[1]->getRelation('translations'));
        expect(fn () => $store->rowsForOwners([$forged]))->toThrow(OwnershipViolation::class);

        return $records;
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($records): void {
        expect(fn () => app(RelatedTranslationStore::class)->rowsForOwners($records))->toThrow(OwnershipViolation::class);
    });
});

it('allows the same self group and locale under different primary owners', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    foreach ([7, 8] as $id) {
        $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id): void {
            $record = app(OwnershipWriter::class)->create(OwnershipSelfRecord::class, fn (OwnershipAssignment $assignment): OwnershipSelfRecord => OwnershipSelfRecord::query()->create([...$assignment->attributes(), 'id' => $id * 10, 'entry_key' => 'shared-group', 'locale' => 'en', 'name' => 'Owner '.$id]));
            app(TranslationWriter::class)->sync($record, ['bg' => ['name' => 'BG '.$id]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record));
            expect($record->translated('name', 'bg'))->toBe('BG '.$id)
                ->and(OwnershipSelfRecord::query()->count())->toBe(2);
        });
    }
});

it('rolls back every locale and discards loaded rows when a later owned write fails', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $record = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $assignment): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$assignment->attributes(), 'id' => 70]));
        $record->load('translations');
        OwnershipRelatedTranslation::creating(static function (OwnershipRelatedTranslation $row): void {
            if ($row->getAttribute('locale') === 'bg') {
                throw new RuntimeException('Rejected second locale.');
            }
        });
        expect(fn () => app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'first'], 'bg' => ['name' => 'second']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record)))->toThrow(RuntimeException::class, 'Rejected second locale.');
        expect(OwnershipRelatedTranslation::query()->count())->toBe(0)
            ->and($record->relationLoaded('translations'))->toBeFalse();
    });
});

it('rejects a foreign loaded child and a forged parent relation before persistence', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $parents = [];
    $rows = [];
    foreach ([7, 8] as $id) {
        $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id, &$parents, &$rows): void {
            $parents[$id] = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $assignment): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$assignment->attributes(), 'id' => $id * 10]));
            $rows[$id] = app(TranslationWriter::class)->sync($parents[$id], ['en' => ['name' => 'Owner '.$id]], SystemActor::Background, app(TranslationResourceVersioner::class)->version($parents[$id]))->getTranslation('en', withFallback: false);
        });
    }
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($parents, $rows): void {
        $parents[7]->setRelation('translations', (new OwnershipRelatedTranslation)->newCollection([$rows[8]]));
        expect(fn () => app(RelatedTranslationStore::class)->rows($parents[7]))->toThrow(OwnershipViolation::class);
        expect(fn () => app(OwnershipWriter::class)->create(OwnershipRelatedTranslation::class, function (OwnershipAssignment $assignment) use ($parents): OwnershipRelatedTranslation {
            $row = new OwnershipRelatedTranslation([...$assignment->attributes(), 'record_id' => 80, 'locale' => 'bg', 'name' => 'forged']);
            $row->setRelation('owner', $parents[7]);
            $row->save();

            return $row;
        }))->toThrow(OwnershipViolation::class);
        expect(OwnershipRelatedTranslation::query()->count())->toBe(1);
    });
});

it('restores queued translation facts to their captured owner after the publishing source commits', function (): void {
    OwnershipTranslationListener::$owners = [];
    Event::listen(TranslationResourceSynced::class, [OwnershipTranslationListener::class, 'handle']);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $fact = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => new TranslationResourceSynced('fixture.records', OwnershipRelatedRecord::class, 70, ['en'], TranslationSyncMode::Patch, SystemActor::class, SystemActor::Background->name, 'before', 'after'));
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($fact): void {
        DB::transaction(function () use ($fact): void {
            app(DomainEventDispatcher::class)->dispatch($fact, DB::connection());
            expect(OwnershipTranslationListener::$owners)->toBe([]);
        });
        expect(OwnershipTranslationListener::$owners)->toBe(['7'])
            ->and(app(OwnershipContext::class)->snapshot()->owner?->key)->toBe('8');
    });
});

it('restores retained content locale state after nested owner callbacks fail', function (): void {
    $locale = app(ContentLocale::class);
    $locale->reset();
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($runner, $actor, $locale): void {
        $locale->set('en');
        expect(fn () => $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($locale): void {
            $locale->set('bg');
            throw new RuntimeException('Nested callback failed.');
        }))->toThrow(RuntimeException::class, 'Nested callback failed.');
        expect($locale->get())->toBe('en')->and($locale->isSet())->toBeTrue();
    });
    expect(Context::has(ContentLocale::CONTEXT_KEY))->toBeFalse()
        ->and(Context::has(ContentLocale::EXPLICIT_CONTEXT_KEY))->toBeFalse();
});

it('excludes technical ownership columns from translated and shared values', function (string $column): void {
    expect(fn () => new RelatedTranslationDefinition(OwnershipRelatedTranslation::class, [$column]))->toThrow(TranslatableException::class)
        ->and(fn () => new SelfTranslationDefinition('entry_key', ['name'], sharedFields: [$column]))->toThrow(TranslatableException::class);
})->with(['owner_scope_key', 'owner_reference']);
it('rejects dirty native primary owner fields in batched eager translation handles', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $record = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $a): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$a->attributes(), 'id' => 701]));
        app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'Private']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record));
        $record->setAttribute('company_id', 8);
        expect(fn () => (new OwnershipRelatedRecord)->newCollection([$record])->load('translations'))->toThrow(OwnershipViolation::class);
    });
});

it('admits an owned canonical owner whose integer key the driver returns as a numeric string', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $record = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $assignment): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$assignment->attributes(), 'id' => 70]));
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        try {
            expect($record->getRawOriginal('id'))->toBe(70);
            $identity = app(RelatedTranslationStore::class)->ownerIdentity($record, $record->translationDefinition());
            app(TranslationWriter::class)->sync($record, ['en' => ['name' => 'Stringified'], 'bg' => ['name' => 'Низ']], SystemActor::Background, app(TranslationResourceVersioner::class)->version($record));

            expect((string) $identity['record_id'])->toBe('70')
                ->and($record->translated('name', 'bg'))->toBe('Низ')
                ->and(OwnershipRelatedTranslation::query()->count())->toBe(2);

            $record->setRawAttributes([...$record->getAttributes(), 'id' => '070'], true);
            expect(fn () => app(RelatedTranslationStore::class)->ownerIdentity($record, $record->translationDefinition()))->toThrow(OwnershipViolation::class);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        }
    });
});

it('admits the locked canonical owner once for a whole locale batch', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $identityReads = static function (int $id, array $locales): int {
            $record = app(OwnershipWriter::class)->create(OwnershipRelatedRecord::class, fn (OwnershipAssignment $assignment): OwnershipRelatedRecord => OwnershipRelatedRecord::query()->create([...$assignment->attributes(), 'id' => $id]));
            $version = app(TranslationResourceVersioner::class)->version($record);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                app(TranslationWriter::class)->sync($record, array_fill_keys($locales, ['name' => 'Name']), SystemActor::Background, $version);

                return count(array_filter(
                    array_column(DB::getQueryLog(), 'query'),
                    static fn (string $sql): bool => str_replace(['"', '`'], '', $sql) === 'select id from translation_ownership_records where id = ? limit 1',
                ));
            } finally {
                DB::disableQueryLog();
            }
        };

        $one = $identityReads(71, ['en']);
        $two = $identityReads(72, ['en', 'bg']);

        expect(OwnershipRelatedTranslation::query()->count())->toBe(3)
            ->and($one)->toBeGreaterThan(0)
            ->and($two - $one)->toBe(1);
    });
});
