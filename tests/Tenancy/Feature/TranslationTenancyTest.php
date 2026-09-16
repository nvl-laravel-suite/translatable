<?php

declare(strict_types=1);

use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslatableOptions;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\SelfTranslationStore;
use Nvl\Translatable\Services\TranslationOwnership;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
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
