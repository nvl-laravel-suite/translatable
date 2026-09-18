<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Translatable\Actions\DeleteTranslationResourceLocaleAction;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Data\DeleteTranslationLocaleData;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslatableOptions;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\SelfTranslationStore;
use Nvl\Translatable\Services\TranslationOwnership;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\Tests\Support\TestTranslatableModelTranslation;
use Nvl\Translatable\TranslatableOptions;

it('rejects a retained owner even when its translations were loaded in another tenant', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'same', ['en' => ['name' => 'A secret']]);
    $s->run($s::A, fn () => $article->load('translations'));

    expect(fn () => $s->run($s::B, fn () => $article->translated('name', 'en')))
        ->toThrow(TenantBoundaryViolation::class);
});

it('admits canonical owners and partitions queries and child identities', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $b = $s->article($s::B, 'same', ['en' => ['name' => 'B']]);
    $ownership = app(TranslationOwnership::class);
    $keyA = $s->run($s::A, function () use ($a, $ownership): string {
        expect($ownership->query($a->newQuery(), $a->translationDefinition())->pluck('id')->all())->toBe([$a->id]);
        expect($ownership->childAttributes($a, $a->translationDefinition()))->toBe(['tenant_id' => TenantTranslationScenario::A]);
        expect($ownership->partitionColumns($a->translationDefinition()))->toBe(['tenant_id']);
        expect($a->translated('name', 'en'))->toBe('A');

        return $ownership->partitionKey($a, $a->translationDefinition());
    });
    $keyB = $s->run($s::B, fn () => $ownership->partitionKey($b, $b->translationDefinition()));
    expect($keyA)->not->toBe($keyB)->toHaveLength(64);
    expect(fn () => $s->run($s::B, fn () => $ownership->childAttributes($a, $a->translationDefinition())))
        ->toThrow(TenantBoundaryViolation::class);
});

it('requires a transaction and rejects dirty ownership and self identity when locking', function (): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'same', 'en', 'A');
    $ownership = app(TranslationOwnership::class);
    $s->run($s::A, function () use ($entry, $ownership): void {
        expect(fn () => $ownership->lockOwner($entry, $entry->translationDefinition()))->toThrow(TenantBoundaryViolation::class);
        $entry->getConnection()->transaction(function () use ($entry, $ownership): void {
            expect($ownership->lockOwner($entry, $entry->translationDefinition())->id)->toBe($entry->id);
            $entry->tenant_id = TenantTranslationScenario::B;
            expect(fn () => $ownership->lockOwner($entry, $entry->translationDefinition()))->toThrow(TenantBoundaryViolation::class);
            $entry->tenant_id = TenantTranslationScenario::A;
            $entry->entry_key = 'forged';
            expect(fn () => $ownership->lockOwner($entry, $entry->translationDefinition()))->toThrow(TenantBoundaryViolation::class);
        });
    });
});

it('creates a second locale only inside the canonical owner partition', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A');
    $s->entry($s::B, 'same', 'bg', 'B');

    $s->run($s::A, fn () => $a->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->patch($a, ['bg' => ['name' => 'A bg']]),
    ));

    expect($s->run($s::A, fn () => $a->getAllTranslations()->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'A bg', 'en' => 'A']);
    expect($s->run($s::B, fn () => TenantSelfEntry::query()->locale('bg')->value('name')))->toBe('B');
});

it('requires the effective owner transaction for every tenant writer mutation', function (string $mutation): void {
    $s = TenantTranslationScenario::install();
    $owner = $s->entry($s::A, 'same', 'en', 'A');

    expect(fn () => $s->run($s::A, fn () => match ($mutation) {
        'upsert' => app(TranslationWriter::class)->upsert($owner, 'bg', ['name' => 'A bg']),
        'patch' => app(TranslationWriter::class)->patch($owner, ['bg' => ['name' => 'A bg']]),
        'replace' => app(TranslationWriter::class)->replace($owner, ['en' => ['name' => 'A replaced']]),
        'delete' => app(TranslationWriter::class)->delete($owner, 'en'),
    }))->toThrow(TenantBoundaryViolation::class, 'transaction');
})->with(['upsert', 'patch', 'replace', 'delete']);

it('replaces and protects final self locales only inside the locked tenant group', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A en');
    $s->entry($s::A, 'same', 'bg', 'A bg');
    $s->entry($s::B, 'same', 'en', 'B en');
    $s->entry($s::B, 'same', 'bg', 'B bg');

    $s->run($s::A, fn () => $a->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->replace($a, ['bg' => ['name' => 'A only']]),
    ));

    expect($s->run($s::A, fn () => $a->getAllTranslations()->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'A only'])
        ->and($s->run($s::B, fn () => TenantSelfEntry::query()->withAllTranslations()->orderBy('locale')->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'B bg', 'en' => 'B en']);

    $remaining = $s->run($s::A, fn () => TenantSelfEntry::query()->where('entry_key', 'same')->firstOrFail());

    expect(fn () => $s->run($s::A, fn () => $remaining->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->delete($remaining, 'bg'),
    )))->toThrow(TranslatableException::class, 'final locale row')
        ->and(fn () => $s->run($s::A, fn () => $remaining->getConnection()->transaction(
            fn () => app(TranslationWriter::class)->replace($remaining, []),
        )))->toThrow(TranslatableException::class, 'retain at least one locale');
});

it('restores or creates only the canonical tenant locale row', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A en');
    $deletedB = $s->entry($s::B, 'same', 'bg', 'B deleted');
    $deletedB->delete();

    $s->run($s::A, fn () => $a->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->patch($a, ['bg' => ['name' => 'A bg']]),
    ));

    expect($s->run($s::A, fn () => $a->getAllTranslations()->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'A bg', 'en' => 'A en'])
        ->and($s->run($s::B, fn () => TenantSelfEntry::withTrashed()->whereKey($deletedB->id)->firstOrFail()->trashed()))
        ->toBeTrue();
});

it('replaces and deletes related rows through canonical owner and ownership columns', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'same', ['en' => ['name' => 'A en'], 'bg' => ['name' => 'A bg']]);
    $b = $s->article($s::B, 'same', ['en' => ['name' => 'B en'], 'bg' => ['name' => 'B bg']]);

    $s->run($s::A, fn () => $a->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->replace($a, ['bg' => ['name' => 'A only']]),
    ));

    expect($s->run($s::A, fn () => $a->translations()->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'A only'])
        ->and($s->run($s::B, fn () => $b->translations()->orderBy('locale')->pluck('name', 'locale')->all()))
        ->toBe(['bg' => 'B bg', 'en' => 'B en']);

    $s->run($s::A, fn () => $a->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->delete($a, 'bg'),
    ));

    expect($s->run($s::A, fn () => $a->translations()->count()))->toBe(0)
        ->and($s->run($s::B, fn () => $b->translations()->count()))->toBe(2);
});

it('rejects structural ownership keys in writer payloads', function (string $column): void {
    $s = TenantTranslationScenario::install();
    $owner = $s->entry($s::A, 'same', 'en', 'A');

    expect(fn () => $s->run($s::A, fn () => $owner->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->patch($owner, [
            'bg' => ['name' => 'A bg', $column => 'forged'],
        ]),
    )))->toThrow(InvalidTranslatableFieldException::class);
})->with(['tenant_id', 'ownership_key', 'entry_key', 'locale']);

it('assigns server-derived identity last in package store write paths', function (): void {
    $s = TenantTranslationScenario::install();
    $selfOwner = $s->entry($s::A, 'same', 'en', 'A');
    $relatedOwner = $s->article($s::A, 'article-a', []);
    $otherOwner = $s->article($s::B, 'article-b', []);

    $self = $s->run($s::A, fn () => $selfOwner->getConnection()->transaction(
        fn () => app(SelfTranslationStore::class)->upsert(
            $selfOwner,
            $selfOwner->translationDefinition(),
            'bg',
            ['name' => 'A bg', 'tenant_id' => $s::B, 'entry_key' => 'forged', 'locale' => 'fr'],
        ),
    ));
    $related = $s->run($s::A, fn () => $relatedOwner->getConnection()->transaction(
        fn () => app(RelatedTranslationStore::class)->upsert(
            $relatedOwner,
            $relatedOwner->translationDefinition(),
            'bg',
            [
                'name' => 'Article bg',
                'tenant_id' => $s::B,
                'article_id' => $otherOwner->id,
                'locale' => 'fr',
            ],
        ),
    ));

    expect($self->only(['tenant_id', 'entry_key', 'locale']))->toBe([
        'tenant_id' => $s::A,
        'entry_key' => 'same',
        'locale' => 'bg',
    ])->and($related->only(['tenant_id', 'article_id', 'locale']))->toBe([
        'tenant_id' => $s::A,
        'article_id' => $relatedOwner->id,
        'locale' => 'bg',
    ]);
});

it('rejects forged self definitions at every public store mutation entry', function (string $mutation): void {
    $s = TenantTranslationScenario::install();
    $source = $s->entry($s::A, 'source', 'en', 'source');
    $victim = $s->entry($s::A, 'victim', 'bg', 'source');
    $forged = new SelfTranslationDefinition(
        groupKey: 'name',
        fields: ['forged_value'],
        localeKey: 'entry_key',
        allowDeletingLastTranslation: true,
        ownershipResource: 'test.entries',
    );

    expect(fn () => $s->run($s::A, fn () => $source->getConnection()->transaction(
        fn () => match ($mutation) {
            'upsert' => app(SelfTranslationStore::class)->upsert($source, $forged, 'victim', []),
            'replace' => app(SelfTranslationStore::class)->deleteExcept($source, $forged, ['source']),
            'delete' => app(SelfTranslationStore::class)->delete($source, $forged, 'victim'),
        },
    )))->toThrow(TranslatableException::class, 'canonical definition')
        ->and($s->run($s::A, fn () => TenantSelfEntry::query()->whereKey($victim->id)->exists()))
        ->toBeTrue();
})->with(['upsert', 'replace', 'delete']);

it('rejects forged related definitions at every public store mutation entry', function (
    string $mutation,
    string $forgery,
): void {
    $s = TenantTranslationScenario::install();
    $source = $s->article($s::A, 'source', []);
    $target = $s->run($s::A, function () use ($s): TenantArticle {
        $target = new TenantArticle(['slug' => 'target']);
        $target->id = $s::A;
        $target->forceFill(app(TenantBoundary::class)->attributes('test.articles'));
        $target->save();

        return $target;
    });
    $forged = match ($forgery) {
        'ownerKey' => new RelatedTranslationDefinition(
            translationModel: TenantArticleTranslation::class,
            fields: ['name'],
            foreignKey: 'article_id',
            ownerKey: 'tenant_id',
            ownershipResource: 'test.articles',
        ),
        'foreignKey' => new RelatedTranslationDefinition(
            translationModel: TenantArticleTranslation::class,
            fields: ['name'],
            foreignKey: 'tenant_id',
            ownershipResource: 'test.articles',
        ),
    };

    expect(fn () => $s->run($s::A, fn () => $source->getConnection()->transaction(
        fn () => match ($mutation) {
            'upsert' => app(RelatedTranslationStore::class)->upsert(
                $source,
                $forged,
                'bg',
                ['name' => 'Redirected'],
            ),
            'replace' => app(RelatedTranslationStore::class)->deleteExcept($source, $forged, ['en']),
            'delete' => app(RelatedTranslationStore::class)->delete($source, $forged, 'en'),
        },
    )))->toThrow(TranslatableException::class, 'canonical definition')
        ->and($s->run($s::A, fn () => $target->translations()->count()))->toBe(0);
})->with(['upsert', 'replace', 'delete'])->with([
    'owner key' => 'ownerKey',
    'foreign key' => 'foreignKey',
]);

it('accepts reconstructed definitions that are structurally identical to the owner declarations', function (): void {
    $s = TenantTranslationScenario::install();
    $selfOwner = $s->entry($s::A, 'self-equivalent', 'en', 'English');
    $relatedOwner = $s->article($s::A, 'related-equivalent', []);
    $selfDefinition = new SelfTranslationDefinition(
        groupKey: 'entry_key',
        fields: ['name'],
        ownershipResource: 'test.entries',
    );
    $relatedDefinition = new RelatedTranslationDefinition(
        translationModel: TenantArticleTranslation::class,
        fields: ['name'],
        foreignKey: 'article_id',
        ownershipResource: 'test.articles',
    );

    $self = $s->run($s::A, fn () => $selfOwner->getConnection()->transaction(
        fn () => app(SelfTranslationStore::class)->upsert(
            $selfOwner,
            $selfDefinition,
            'bg',
            ['name' => 'Bulgarian'],
        ),
    ));
    $related = $s->run($s::A, fn () => $relatedOwner->getConnection()->transaction(
        fn () => app(RelatedTranslationStore::class)->upsert(
            $relatedOwner,
            $relatedDefinition,
            'bg',
            ['name' => 'Related Bulgarian'],
        ),
    ));

    expect($self->locale)->toBe('bg')
        ->and($related->locale)->toBe('bg');
});

it('rejects quiet mutations of every persisted self-row identity column', function (
    string $column,
    string $value,
    string $wrapper,
): void {
    $s = TenantTranslationScenario::install();
    $owner = $s->entry($s::A, 'same', 'en', 'A');
    $owner->forceFill([$column => $value]);

    expect(fn () => match ($wrapper) {
        'save' => $owner->saveQuietly(),
        'update' => $owner->updateQuietly(),
        'push' => $owner->pushQuietly(),
    })
        ->toThrow(TranslatableException::class, 'immutable after creation');
})->with([
    'tenant' => ['tenant_id', TenantTranslationScenario::B],
    'ownership key' => ['ownership_key', 'tenant:'.TenantTranslationScenario::B],
    'group' => ['entry_key', 'other'],
    'locale' => ['locale', 'bg'],
])->with(['save', 'update', 'push']);

it('retries a sequential absent-locale insert through the savepoint duplicate path', function (): void {
    $s = TenantTranslationScenario::install();
    $owner = $s->entry($s::A, 'same', 'en', 'A en');
    $s->entry($s::A, 'same', 'bg', 'Existing bg');
    $hiddenOnce = false;
    TenantSelfEntry::addGlobalScope('hide_first_locale_lookup', function (Builder $query) use (&$hiddenOnce): void {
        $hasLocalePredicate = collect($query->getQuery()->wheres ?? [])->contains(
            static fn (array $where): bool => ($where['column'] ?? null) === 'locale',
        );

        if ($hasLocalePredicate && ! $hiddenOnce) {
            $hiddenOnce = true;
            $query->whereRaw('0 = 1');
        }
    });

    $written = $s->run($s::A, fn () => $owner->getConnection()->transaction(
        fn () => app(TranslationWriter::class)->upsert($owner, 'bg', ['name' => 'Retried bg']),
    ));

    expect($written->name)->toBe('Retried bg')
        ->and($s->run($s::A, fn () => TenantSelfEntry::query()->withoutGlobalScope('hide_first_locale_lookup')
            ->where('entry_key', 'same')->where('locale', 'bg')->count()))->toBe(1);
});

it('writes on the owners non-default effective connection', function (): void {
    $default = app('db')->getDefaultConnection();
    config([
        'database.connections.tenant_alt' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
    ]);
    try {
        $s = TenantTranslationScenario::install(connection: 'tenant_alt');
        $owner = $s->entry($s::A, 'same', 'en', 'A');

        $s->run($s::A, fn () => $owner->getConnection()->transaction(
            fn () => app(TranslationWriter::class)->patch($owner, ['bg' => ['name' => 'A bg']]),
        ));
        $translations = $s->run($s::A, fn () => $owner->getAllTranslations()->pluck('name', 'locale')->all());

        expect($owner->getConnectionName())->toBe('tenant_alt')
            ->and(app('db')->getDefaultConnection())->toBe('tenant_alt')
            ->and($translations)->toBe(['bg' => 'A bg', 'en' => 'A']);
    } finally {
        app('db')->setDefaultConnection($default);
    }

    expect(app('db')->getDefaultConnection())->toBe($default);
});

it('keeps central optimistic version checks inside the tenant write transaction', function (): void {
    $s = TenantTranslationScenario::install();
    $owner = $s->article($s::A, 'same', []);
    $version = $s->run($s::A, fn () => app(TranslationResourceVersioner::class)->version($owner));
    $sync = app(SyncTranslationResourceAction::class);
    $actor = TranslationActorData::system('test');

    $s->run($s::A, fn () => $sync->execute(
        'test.articles',
        $owner->id,
        new TranslationMutationData(['en' => ['name' => 'First']], $version),
        $actor,
    ));

    expect(fn () => $s->run($s::A, fn () => $sync->execute(
        'test.articles',
        $owner->id,
        new TranslationMutationData(
            ['bg' => ['name' => 'Stale']],
            $version,
            TranslationSyncMode::Replace,
        ),
        $actor,
    )))->toThrow(TranslationResourceException::class, 'changed after it was read');
});

it('returns a reusable central self version after updating the current representative', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'versioned', 'en', 'Initial');
    $actor = TranslationActorData::system('test');
    $sync = app(SyncTranslationResourceAction::class);

    try {
        Carbon::setTestNow('2026-09-16 12:00:01');
        $initialVersion = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)
            ->find('test.entries', 'versioned', $actor)->version);
        $first = $s->run($s::A, fn () => $sync->execute(
            'test.entries',
            'versioned',
            new TranslationMutationData(['en' => ['name' => 'First']], $initialVersion),
            $actor,
        ));

        Carbon::setTestNow('2026-09-16 12:00:02');
        $second = $s->run($s::A, fn () => $sync->execute(
            'test.entries',
            'versioned',
            new TranslationMutationData(['bg' => ['name' => 'Second']], $first->version),
            $actor,
        ));

        expect($second->version)->toHaveLength(64);
    } finally {
        Carbon::setTestNow();
    }
});

it('returns a reusable central self version after :dataset changes the representative', function (string $mutation): void {
    $s = TenantTranslationScenario::install();
    $actor = TranslationActorData::system('test');
    $sync = app(SyncTranslationResourceAction::class);

    try {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $s->entry($s::A, 'representative', 'en', 'English');
        Carbon::setTestNow('2026-09-16 12:00:01');
        $s->entry($s::A, 'representative', 'bg', 'Bulgarian');
        $initialVersion = $s->run($s::A, fn () => app(TranslationResourceGatherer::class)
            ->find('test.entries', 'representative', $actor)->version);

        Carbon::setTestNow('2026-09-16 12:00:02');
        $version = $s->run($s::A, fn () => match ($mutation) {
            'replace' => $sync->execute(
                'test.entries',
                'representative',
                new TranslationMutationData(
                    ['en' => ['name' => 'Only English']],
                    $initialVersion,
                    TranslationSyncMode::Replace,
                ),
                $actor,
            )->version,
            'delete' => app(DeleteTranslationResourceLocaleAction::class)->execute(
                'test.entries',
                'representative',
                new DeleteTranslationLocaleData('bg', $initialVersion),
                $actor,
            )->version,
        });

        Carbon::setTestNow('2026-09-16 12:00:03');
        $next = $s->run($s::A, fn () => $sync->execute(
            'test.entries',
            'representative',
            new TranslationMutationData(['en' => ['name' => 'Next']], $version),
            $actor,
        ));

        expect($next->version)->toHaveLength(64);
    } finally {
        Carbon::setTestNow();
    }
})->with(['replace', 'delete']);

it('rejects undeclared ownership in enabled contexts', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'same', []);
    $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name']);
    $s->run($s::A, function () use ($article, $definition): void {
        $ownership = app(TranslationOwnership::class);
        expect(fn () => $ownership->assertOwner($article, $definition))->toThrow(TenantConfigurationInvalid::class);
        expect(fn () => $ownership->query($article->newQuery(), $definition))->toThrow(TenantConfigurationInvalid::class);
    });
});

it('denies adopted storage after disabling and removing the declaration', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'same', []);
    config(['tenancy.enabled' => false]);
    app()->forgetScopedInstances();
    $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name']);
    $ownership = app(TranslationOwnership::class);
    expect($ownership->partitionColumns($definition))->toBe([]);
    expect(fn () => $ownership->assertOwner($article, $definition))->toThrow(TenantSchemaNotReady::class);
    expect(fn () => $ownership->query($article->newQuery(), $definition))->toThrow(TenantSchemaNotReady::class);
});

it('preserves ownership declarations in legacy option adapters', function (): void {
    $related = (new TenantArticle)->translationDefinition();
    $self = (new TenantSelfEntry)->translationDefinition();
    expect(TranslatableOptions::fromDefinition($related)->toDefinition()->ownershipResource)->toBe('test.articles');
    expect(SelfTranslatableOptions::fromDefinition($self)->toDefinition()->ownershipResource)->toBe('test.entries');
});

it('rejects ownership columns in translated and shared payload declarations', function (string $column): void {
    expect(fn () => new SelfTranslationDefinition('entry_key', [$column]))->toThrow(TranslatableException::class);
    expect(fn () => new SelfTranslationDefinition('entry_key', ['name'], sharedFields: [$column]))->toThrow(TranslatableException::class);
})->with(['tenant_id', 'ownership_key']);

it('derives inherited child ownership from persisted parents despite forged in-memory values', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $other = $s->article($s::B, 'same', ['en' => ['name' => 'B']]);
    $s->run($s::A, function () use ($article, $other): void {
        $child = $article->translations()->firstOrFail();
        $child->setAttribute('tenant_id', TenantTranslationScenario::B);
        $child->setAttribute('article_id', $other->id);
        $child->setRelation('article', $other);
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'test.article-translations');
        $ownership = app(TranslationOwnership::class);
        expect($ownership->partitionColumns($definition))->toBe(['tenant_id']);
        expect($ownership->childAttributes($child, $definition))->toBe(['tenant_id' => TenantTranslationScenario::A]);
        expect($ownership->partitionKey($child, $definition))->toHaveLength(64);
    });
});

it('keys grouped translations by persisted partition and logical identity', function (): void {
    $s = TenantTranslationScenario::install();
    config(['translatable.locales' => ['en', 'fr']]);
    $first = $s->entry($s::A, 'same', 'en', 'A');
    $second = $s->entry($s::A, 'same', 'fr', 'A fr');
    $foreign = $s->entry($s::B, 'same', 'en', 'B');
    $ownership = app(TranslationOwnership::class);
    $key = $s->run($s::A, function () use ($first, $second, $ownership): string {
        $key = $ownership->partitionKey($first, $first->translationDefinition());
        $second->entry_key = 'forged';
        expect($ownership->partitionKey($second, $second->translationDefinition()))->toBe($key);

        return $key;
    });
    expect($s->run($s::B, fn () => $ownership->partitionKey($foreign, $foreign->translationDefinition())))->not->toBe($key);
});

it('probes undeclared storage on its actual connection after the default changes', function (): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'same', []);
    $canonical = $article->getConnection()->getName();
    $article->setConnection($canonical);
    config(['tenancy.enabled' => false, 'database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    app('db')->setDefaultConnection('legacy');
    app()->forgetScopedInstances();
    $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name']);
    $ownership = app(TranslationOwnership::class);
    expect(fn () => $ownership->assertOwner($article, $definition))->toThrow(TenantSchemaNotReady::class);
    expect(fn () => $ownership->query($article->newQuery(), $definition))->toThrow(TenantSchemaNotReady::class);
    $legacy = (new TenantArticle)->setConnection('legacy');
    $ownership->assertOwner($legacy, $definition);
    expect($ownership->query($legacy->newQuery(), $definition)->getConnection()->getName())->toBe('legacy');
    app('db')->setDefaultConnection($canonical);
});

it('rejects retained self rows and both store fast paths in a later tenant context', function (): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'same', 'en', 'A');
    $article = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $s->run($s::A, function () use ($entry, $article): void {
        $entry->setRelation('translations', $entry->getAllTranslations());
        $article->load('translations');
    });
    $s->run($s::B, function () use ($entry, $article): void {
        expect(fn () => $entry->translated('name', 'en'))->toThrow(TenantBoundaryViolation::class);
        expect(fn () => app(SelfTranslationStore::class)->rows($entry))->toThrow(TenantBoundaryViolation::class);
        expect(fn () => app(RelatedTranslationStore::class)->rows($article))->toThrow(TenantBoundaryViolation::class);
    });
});

it('bounds cold legacy adoption probes once per actual connection', function (): void {
    $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name']);
    $owner = new TenantArticle;
    $ownership = app(TranslationOwnership::class);
    $firstConnection = $owner->getConnection();
    $firstConnection->enableQueryLog();
    $ownership->assertOwner($owner, $definition);
    $coldCount = count($firstConnection->getQueryLog());
    expect($coldCount)->toBeGreaterThan(0)->toBeLessThanOrEqual(2);
    for ($index = 0; $index < 25; $index++) {
        $ownership->assertOwner($owner, $definition);
        $ownership->query($owner->newQuery(), $definition);
    }
    expect($firstConnection->getQueryLog())->toHaveCount($coldCount);
    config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $other = (new TenantArticle)->setConnection('probe');
    $secondConnection = $other->getConnection();
    $secondConnection->enableQueryLog();
    $ownership->assertOwner($other, $definition);
    expect($secondConnection->getQueryLog())->toHaveCount(1);
    $ownership->assertOwner($other, $definition);
    expect($secondConnection->getQueryLog())->toHaveCount(1);
    $firstConnection->disableQueryLog();
    $secondConnection->disableQueryLog();
});

it('rejects changed canonical inherited parent identity when locking', function (bool $persistedChange): void {
    $scenario = TenantTranslationScenario::install();
    $article = $scenario->article($scenario::A, 'original', ['en' => ['name' => 'Original']]);
    $other = $scenario->article($scenario::A, 'other', []);
    $scenario->run($scenario::A, function () use ($article, $other, $persistedChange): void {
        $child = $article->translations()->firstOrFail();
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'test.article-translations');
        $ownership = app(TranslationOwnership::class);
        $child->getConnection()->transaction(function () use ($child, $other, $definition, $ownership, $persistedChange): void {
            $child->setAttribute('name', 'Unpersisted content');
            expect($ownership->lockOwner($child, $definition)->getKey())->toBe($child->getKey());
            if ($persistedChange) {
                $child->getConnection()->table($child->getTable())->where('id', $child->getKey())->update(['article_id' => $other->id]);
            } else {
                $child->setAttribute('article_id', $other->id);
            }
            expect(fn () => $ownership->lockOwner($child, $definition))->toThrow(TenantBoundaryViolation::class);
        });
    });
})->with(['dirty parent foreign key' => false, 'persisted same-tenant parent switch' => true]);

it('chooses A fallback even when B has the requested locale for the same group', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'shared-handle', 'en', 'A fallback');
    $s->entry($s::B, 'shared-handle', 'bg', 'B requested');

    $names = $s->run($s::A, fn () => TenantSelfEntry::query()->locale('bg')->pluck('name')->all());

    expect($names)->toBe(['A fallback']);
});

it('partitions self helpers and scopes even without a model tenant scope', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->entry($s::A, 'same', 'en', 'A fallback');
    $s->entry($s::B, 'same', 'bg', 'B requested');
    $s->run($s::A, function () use ($a): void {
        expect($a->getAllTranslations()->pluck('name')->all())->toBe(['A fallback']);
        expect($a->hasTranslation('bg'))->toBeFalse();
        expect(app(SelfTranslationStore::class)->rows($a)->pluck('name')->all())->toBe(['A fallback']);
        expect(TenantSelfEntry::query()->withAllTranslations()->pluck('name')->all())->toBe(['A fallback']);
        expect(TenantSelfEntry::query()->translationGroup('same')->pluck('name')->all())->toBe(['A fallback']);
        expect(TenantSelfEntry::query()->where('name', 'B requested')->orWhere('name', 'A fallback')->whereTranslated('name', 'A fallback', locale: 'en')->pluck('name')->all())->toBe(['A fallback']);
    });
});

it('rejects injected translations from another partition or owner', function (string $strategy, string $read, bool $sameTenant): void {
    $s = TenantTranslationScenario::install();
    if ($strategy === 'self') {
        $owner = $s->entry($s::A, 'same', 'en', 'A');
        $other = $s->entry($sameTenant ? $s::A : $s::B, $sameTenant ? 'other' : 'same', 'bg', 'Other');
        $rows = new Collection([$other]);
    } else {
        $owner = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
        $other = $s->article($sameTenant ? $s::A : $s::B, 'other', ['bg' => ['name' => 'Other']]);
        $rows = $s->run($sameTenant ? $s::A : $s::B, fn () => $other->translations()->get());
    }
    $owner->setRelation('translations', $rows);
    expect(fn () => $s->run($s::A, fn () => match ($read) {
        'all' => $owner->getAllTranslations(),
        'exact' => $owner->getTranslation('bg', false),
        'has' => $owner->hasTranslation('bg'),
        'locales' => $owner->getAvailableLocales(),
        'fields' => $owner->getTranslatedAttributes('bg'),
        'store' => app($strategy === 'self' ? SelfTranslationStore::class : RelatedTranslationStore::class)->rows($owner),
    }))->toThrow(TenantBoundaryViolation::class);
})->with(['self' => ['self'], 'related' => ['related']])->with(['all', 'exact', 'has', 'locales', 'fields', 'store'])->with(['other tenant' => false, 'other owner' => true]);

it('keeps native related reads inside the canonical owner partition', function (string $read): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $s->article($s::B, 'same', ['en' => ['name' => 'B']]);
    if ($a->getConnection()->getDriverName() === 'pgsql') {
        expect(collect(Schema::getForeignKeys('tenant_test_article_translations'))->contains(
            static fn (array $foreign): bool => $foreign['columns'] === ['tenant_id', 'article_id']
                && $foreign['foreign_columns'] === ['tenant_id', 'id'],
        ))->toBeTrue();
    } else {
        Schema::disableForeignKeyConstraints();
        try {
            TenantArticleTranslation::forceCreate(['tenant_id' => $s::B, 'article_id' => $a->id, 'locale' => 'bg', 'name' => 'B injected']);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
    $s->run($s::A, function () use ($a, $read): void {
        $names = match ($read) {
            'lazy' => $a->translations->pluck('name')->all(),
            'explicit' => $a->translations()->get()->pluck('name')->all(),
            'eager' => TenantArticle::query()->whereKey($a->id)->with('translations')->firstOrFail()->translations->pluck('name')->all(),
            'one' => [$a->translation('bg')->first()?->getAttribute('name')],
            'existence' => TenantArticle::query()->whereTranslated('name', 'B injected', locale: 'bg')->pluck('slug')->all(),
        };
        expect($names)->toBe(match ($read) {
            'one' => [null], 'existence' => [], default => ['A']
        });
    });
})->with(['lazy', 'explicit', 'eager', 'one', 'existence']);

it('rejects retained native related reads in another context without resetting scoped instances', function (string $read): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $s->run($s::A, fn () => $a->load(['translations', 'translation']));
    expect(fn () => $s->run($s::B, fn () => match ($read) {
        'lazy' => $a->translations,
        'one' => $a->translation,
        'explicit' => $a->translations()->get(),
        'eager' => $a->load('translations'),
    }))->toThrow(TenantBoundaryViolation::class);
})->with(['lazy', 'one', 'explicit', 'eager']);

it('applies each fallback policy only to A rows', function (TranslationFallbackPolicy $policy, string $storedLocale, ?string $expected): void {
    $s = TenantTranslationScenario::install();
    config(['translatable.locales' => ['en', 'bg', 'fr'], 'translatable.fallback.policy' => $policy->value]);
    $entry = $s->entry($s::A, 'same', $storedLocale, 'A fallback');
    $s->entry($s::B, 'same', 'bg', 'B requested');
    $article = $s->article($s::A, 'same', [$storedLocale => ['name' => 'A fallback']]);
    $s->article($s::B, 'same', ['bg' => ['name' => 'B requested']]);
    $s->run($s::A, function () use ($entry, $article, $expected): void {
        expect(TenantSelfEntry::query()->locale('bg')->pluck('name')->all())->toBe($expected === null ? [] : [$expected]);
        expect($entry->translated('name', 'bg'))->toBe($expected);
        expect($article->translated('name', 'bg'))->toBe($expected);
        expect($entry->getTranslation('bg', false))->toBeNull();
        expect($article->getTranslation('bg', false))->toBeNull();
    });
})->with([
    'exact' => [TranslationFallbackPolicy::ExactOnly, 'en', null],
    'configured' => [TranslationFallbackPolicy::Configured, 'en', 'A fallback'],
    'any available' => [TranslationFallbackPolicy::AnyAvailable, 'fr', 'A fallback'],
]);

it('preserves null fallback and intentional empty strings within A', function (?string $value, string $expected): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'same', 'en', 'A fallback');
    $localized = $s->entry($s::A, 'same', 'bg', 'temporary');
    $localized->name = $value;
    $localized->save();
    $s->entry($s::B, 'same', 'en', 'B fallback');
    $article = $s->article($s::A, 'same', ['en' => ['name' => 'A fallback'], 'bg' => ['name' => 'temporary']]);
    TenantArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'bg')->update(['name' => $value]);
    $s->article($s::B, 'same', ['en' => ['name' => 'B fallback']]);
    $s->run($s::A, function () use ($entry, $article, $expected): void {
        expect($entry->translated('name', 'bg'))->toBe($expected);
        expect($article->translated('name', 'bg'))->toBe($expected);
    });
})->with(['null falls back' => [null, 'A fallback'], 'empty is intentional' => ['', '']]);

it('retains caller and global visibility constraints in preferred locale candidates', function (bool $global): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A visible');
    $s->entry($s::A, 'same', 'bg', 'A hidden');
    $s->entry($s::B, 'same', 'bg', 'B visible');
    if ($global) {
        TenantSelfEntry::addGlobalScope('fixture_visibility', fn (Builder $query) => $query->where('name', '!=', 'A hidden'));
    }
    $s->run($s::A, function () use ($global): void {
        $query = TenantSelfEntry::query();
        if (! $global) {
            $query->where('name', '!=', 'A hidden');
        }
        expect($query->orderByDesc('locale')->limit(1)->locale('bg')->pluck('name')->all())->toBe(['A visible']);
    });
})->with(['caller' => false, 'global' => true]);

it('excludes a deleted A locale until restored without selecting B', function (): void {
    $s = TenantTranslationScenario::install();
    $s->entry($s::A, 'same', 'en', 'A fallback');
    $deleted = $s->entry($s::A, 'same', 'bg', 'A restored');
    $s->entry($s::B, 'same', 'bg', 'B requested');
    $deleted->delete();
    $s->run($s::A, function () use ($deleted): void {
        expect(TenantSelfEntry::query()->locale('bg')->pluck('name')->all())->toBe(['A fallback']);
        $deleted->restore();
        expect(TenantSelfEntry::query()->locale('bg')->pluck('name')->all())->toBe(['A restored']);
    });
});

it('rejects dirty owner identity across related and self reads', function (string $strategy, string $column): void {
    $s = TenantTranslationScenario::install();
    $owner = $strategy === 'self' ? $s->entry($s::A, 'same', 'en', 'A') : $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $s->run($s::A, function () use ($owner, $column): void {
        $owner->setRelation('translations', $owner->getAllTranslations());
        $owner->setAttribute($column, TenantTranslationScenario::B);
        expect(fn () => $owner->getAllTranslations())->toThrow(TenantBoundaryViolation::class);
    });
})->with(['self' => ['self'], 'related' => ['related']])->with(['tenant_id', 'id']);

it('fails closed without context for row scopes and loaded native reads', function (): void {
    $s = TenantTranslationScenario::install();
    $entry = $s->entry($s::A, 'same', 'en', 'A');
    $article = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
    $s->run($s::A, fn () => $article->load(['translations', 'translation']));
    foreach ([
        fn () => TenantSelfEntry::query()->locale('en')->get(),
        fn () => TenantSelfEntry::query()->withAllTranslations()->get(),
        fn () => TenantArticle::query()->whereTranslated('name', 'A')->get(),
        fn () => TenantArticle::query()->orderByTranslated('name')->get(),
        fn () => $entry->hasTranslation('en'),
        fn () => $article->translations,
        fn () => $article->translation,
        fn () => $article->translations()->get(),
        fn () => $article->getTranslatedAttributes(),
    ] as $read) {
        expect($read)->toThrow(TenantContextMissing::class);
    }
});

it('keeps combined native eager relations and caller conditions intact', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'first', ['en' => ['name' => 'A en'], 'bg' => ['name' => 'A bg']]);
    $b = $s->article($s::B, 'other', ['en' => ['name' => 'B']]);
    $s->run($s::A, function () use ($a, $b): void {
        $loaded = TenantArticle::query()->whereKey($a->id)->with(['translations', 'translation'])->firstOrFail();
        expect($loaded->relationLoaded('translations'))->toBeTrue();
        expect($loaded->relationLoaded('translation'))->toBeTrue();
        expect($loaded->translations->pluck('name')->sort()->values()->all())->toBe(['A bg', 'A en']);
        expect($loaded->translation->getAttribute('name'))->toBe('A en');
        expect(TenantArticle::query()->whereKey($b->id)->orWhere('slug', 'first')->whereTranslated('name', 'A en', locale: 'en')->pluck('id')->all())->toBe([$a->id]);
        expect(TenantArticle::query()->whereKey($b->id)->whereTranslated('name', 'A en', locale: 'en')->get())->toBeEmpty();
        expect(TenantArticle::query()->withAllTranslations()->pluck('id')->all())->toBe([$a->id]);
        expect(TenantArticle::query()->orderByTranslated('name', locale: 'en')->pluck('id')->all())->toBe([$a->id]);
    });
});

it('keeps explicit and eager relation OR predicates within the owner boundary', function (bool $eager): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', ['en' => ['name' => 'A']]);
    $b = $s->article($s::B, 'b', ['bg' => ['name' => 'B']]);
    $s->run($s::A, function () use ($a, $b, $eager): void {
        $names = $eager
            ? $a->load(['translations' => fn ($query) => $query->orWhere('article_id', $b->id)])->translations->pluck('name')->all()
            : $a->translations()->orWhere('article_id', $b->id)->pluck('name')->all();
        expect($names)->toBe(['A']);
    });
})->with(['explicit' => false, 'eager' => true]);

it('keeps explicit relation ownership after caller scope removal and OR widening', function (bool $allScopes): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', ['en' => ['name' => 'A']]);
    $b = $s->article($s::B, 'b', ['en' => ['name' => 'B']]);

    $s->run($s::A, function () use ($a, $b, $allScopes): void {
        $relation = $a->translations();
        $allScopes
            ? $relation->withoutGlobalScopes()
            : $relation->withoutGlobalScope('translation_owner');

        expect($relation->orWhere('article_id', $b->id)->pluck('name')->all())->toBe(['A']);
    });
})->with(['named ownership scope' => false, 'all scopes' => true]);

it('does not hydrate widened eager rows from another ownership partition', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', ['en' => ['name' => 'A']]);
    $b = $s->article($s::B, 'b', ['en' => ['name' => 'B']]);
    $hydrated = [];
    TenantArticleTranslation::retrieved(static function (TenantArticleTranslation $translation) use (&$hydrated): void {
        $hydrated[] = $translation->name;
    });

    $s->run($s::A, function () use ($a, $b, &$hydrated): void {
        $a->load(['translations' => fn ($query) => $query->orWhere('article_id', $b->id)]);

        expect($hydrated)->toBe(['A']);
    });
});

it('keeps widened existence callbacks inside the final ownership boundary', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', []);
    $b = $s->article($s::B, 'b', ['en' => ['name' => 'B']]);

    $exists = $s->run($s::A, fn (): bool => TenantArticle::query()
        ->whereKey($a->id)
        ->whereHas('translations', fn (Builder $query): Builder => $query->orWhere('article_id', $b->id))
        ->exists());

    expect($exists)->toBeFalse();
});

it('keeps late query callbacks inside the final ownership boundary', function (): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', ['en' => ['name' => 'A']]);
    $b = $s->article($s::B, 'b', ['en' => ['name' => 'B']]);

    $names = $s->run($s::A, fn (): array => $a->translations()
        ->beforeQuery(fn ($query) => $query->orWhere('article_id', $b->id))
        ->pluck('name')
        ->all());

    expect($names)->toBe(['A']);
});

it('keeps final native relation owner constraints exact within one partition', function (string $read): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', $read === 'existence' ? [] : ['en' => ['name' => 'A']]);
    $b = $s->article($s::A, 'b', ['en' => ['name' => 'B']]);

    $result = $s->run($s::A, function () use ($a, $b, $read): array|bool {
        if ($read === 'explicit') {
            return $a->translations()->orWhere('article_id', $b->id)->pluck('name')->all();
        }
        if ($read === 'eager') {
            $hydrated = [];
            TenantArticleTranslation::retrieved(static function (TenantArticleTranslation $translation) use (&$hydrated): void {
                $hydrated[] = $translation->name;
            });
            $a->load(['translations' => fn ($query) => $query->orWhere('article_id', $b->id)]);

            return $hydrated;
        }

        return TenantArticle::query()
            ->whereKey($a->id)
            ->whereHas('translations', fn (Builder $query): Builder => $query->orWhere('article_id', $b->id))
            ->exists();
    });

    expect($result)->toBe($read === 'existence' ? false : ['A']);
})->with(['explicit', 'eager', 'existence']);

it('rejects related storage alias replacement after eager and existence callbacks', function (string $read): void {
    $s = TenantTranslationScenario::install();
    $a = $s->article($s::A, 'a', ['en' => ['name' => 'A']]);

    expect(fn () => $s->run($s::A, fn () => match ($read) {
        'eager' => $a->load(['translations' => fn ($query) => $query->from('tenant_test_article_translations as replaced')]),
        'existence' => TenantArticle::query()->whereHas(
            'translations',
            fn (Builder $query): Builder => $query->from('tenant_test_article_translations as replaced'),
        )->exists(),
    }))->toThrow(TenantBoundaryViolation::class);
})->with(['eager', 'existence']);

it('independently admits declared child storage for query and loaded reads', function (string $marker, bool $loaded): void {
    $s = TenantTranslationScenario::install();
    $article = $s->article($s::A, 'a', ['en' => ['name' => 'A']]);
    $markers = DB::table('nvl_tenancy_installation_state')->where('resource', 'test.article-translations');
    if ($marker === 'missing') {
        $markers->delete();
    } else {
        $markers->update(['configuration_hash' => str_repeat('0', 64)]);
    }
    app(TenantInstallationState::class)->invalidate();
    if ($loaded) {
        $article->setRelation('translations', new Collection);
    }

    expect(fn () => $s->run($s::A, fn () => $loaded
        ? $article->getAllTranslations()
        : $article->translations()->get()))->toThrow(TenantSchemaNotReady::class);
})->with(['missing' => ['missing'], 'incompatible' => ['incompatible']])->with(['query' => false, 'loaded empty' => true]);

it('admits actual related storage independently of an unadopted legacy owner connection', function (bool $loaded): void {
    TenantTranslationScenario::install();
    config(['tenancy.enabled' => false, 'database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    app()->forgetScopedInstances();
    $owner = new class extends TestTranslatableModel
    {
        /** Declare a legacy translation model explicitly on the adopted default connection. */
        protected function defineTranslations(): RelatedTranslationDefinition
        {
            $child = new class extends TestTranslatableModelTranslation
            {
                /** Retain the configured translation connection even when the owner differs. */
                public function getConnectionName(): ?string
                {
                    return app('db')->getDefaultConnection();
                }
            };

            return new RelatedTranslationDefinition($child::class, ['name'], foreignKey: 'test_translatable_model_id');
        }
    };
    $owner->setConnection('legacy');
    app(TranslationOwnership::class)->assertOwner($owner, $owner->translationDefinition());
    if ($loaded) {
        $owner->setRelation('translations', new Collection);
    }
    expect(fn () => $owner->getAllTranslations())->toThrow(TenantSchemaNotReady::class);
})->with(['query' => false, 'loaded' => true]);

it('rejects injected native singular and grouped relation properties', function (string $relation): void {
    $s = TenantTranslationScenario::install();
    if ($relation === 'self') {
        $a = $s->entry($s::A, 'same', 'en', 'A');
        $b = $s->entry($s::B, 'same', 'en', 'B');
        $a->setRelation('translations', new Collection([$b]));
        expect(fn () => $s->run($s::A, fn () => $a->translations))->toThrow(TenantBoundaryViolation::class);
    } else {
        $a = $s->article($s::A, 'same', ['en' => ['name' => 'A']]);
        $b = $s->article($s::B, 'same', ['en' => ['name' => 'B']]);
        $a->setRelation('translation', $s->run($s::B, fn () => $b->translation('en')->firstOrFail()));
        expect(fn () => $s->run($s::A, fn () => $a->translation))->toThrow(TenantBoundaryViolation::class);
    }
})->with(['self' => ['self'], 'singular' => ['singular']]);

it('correlates mixed platform locale candidates through a non-null ownership key', function (): void {
    $s = TenantTranslationScenario::install(mixedEntries: true);
    $s->entry($s::A, 'same', 'bg', 'A requested');
    $s->entry($s::B, 'same', 'bg', 'B requested');
    app(TenantRunner::class)->platform(new PlatformOperation('fixture.adoption', 'test', 'fixture'), function (): void {
        $owner = new TenantSelfEntry(['entry_key' => 'same', 'locale' => 'en', 'name' => 'Platform fallback']);
        $owner->forceFill(app(TenantBoundary::class)->attributes('test.entries'))->save();
        expect(TenantSelfEntry::query()->locale('bg')->pluck('name')->all())->toBe(['Platform fallback']);
        $preferred = new TenantSelfEntry(['entry_key' => 'same', 'locale' => 'bg', 'name' => 'Platform requested']);
        $preferred->forceFill(app(TenantBoundary::class)->attributes('test.entries'))->save();
        expect(TenantSelfEntry::query()->locale('bg')->pluck('name')->all())->toBe(['Platform requested']);
        expect($owner->getAllTranslations()->pluck('name')->all())->toBe(['Platform requested', 'Platform fallback']);
    });
    expect($s->run($s::A, fn () => TenantSelfEntry::query()->locale('bg')->pluck('name')->all()))->toBe(['A requested']);
});
