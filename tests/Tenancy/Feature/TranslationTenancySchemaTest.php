<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;

it('names the tenant translation uniqueness and composite owner constraints', function (): void {
    TenantTranslationScenario::install();

    $entryIndexes = collect(Schema::getIndexes('tenant_test_entries'))->keyBy('name');
    $articleIndexes = collect(Schema::getIndexes('tenant_test_articles'))->keyBy('name');
    $translationIndexes = collect(Schema::getIndexes('tenant_test_article_translations'))->keyBy('name');
    $foreignKeys = collect(Schema::getForeignKeys('tenant_test_article_translations'));

    expect($entryIndexes->get('tenant_entries_group_locale_unique'))
        ->toMatchArray(['columns' => ['tenant_id', 'entry_key', 'locale'], 'unique' => true])
        ->and($articleIndexes->get('tenant_articles_tenant_id_unique'))
        ->toMatchArray(['columns' => ['tenant_id', 'id'], 'unique' => true])
        ->and($translationIndexes->get('tenant_article_locale_unique'))
        ->toMatchArray(['columns' => ['tenant_id', 'article_id', 'locale'], 'unique' => true])
        ->and($foreignKeys->contains(
            static fn (array $foreign): bool => $foreign['columns'] === ['tenant_id', 'article_id']
                && $foreign['foreign_table'] === 'tenant_test_articles'
                && $foreign['foreign_columns'] === ['tenant_id', 'id']
                && mb_strtolower($foreign['on_delete']) === 'cascade',
        ))->toBeTrue();
});

it('rejects raw rows that duplicate a tenant locale or cross the canonical owner tenant', function (): void {
    $scenario = TenantTranslationScenario::install();
    $entry = $scenario->entry($scenario::A, 'same', 'en', 'A');
    $article = $scenario->article($scenario::A, 'article', ['en' => ['name' => 'A']]);

    expect(fn () => DB::table('tenant_test_entries')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $scenario::A,
        'entry_key' => $entry->entry_key,
        'locale' => $entry->locale,
        'name' => 'Duplicate',
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('tenant_test_article_translations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $scenario::A,
            'article_id' => $article->id,
            'locale' => 'en',
            'name' => 'Duplicate related locale',
        ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('tenant_test_article_translations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $scenario::B,
            'article_id' => $article->id,
            'locale' => 'bg',
            'name' => 'Cross tenant',
        ]))->toThrow(QueryException::class);
});
