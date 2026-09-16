<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use RuntimeException;

/** Owns only the explicit test tables and their tenant constraints. */
final class TenantTranslationFixtureAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Select the domain fixture schema explicitly before real adoption. */
    public function __construct(private readonly bool $mixedEntries = false) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['test.entries', 'test.articles', 'test.article-translations'];
    }

    /** Prepare the empty fixture schema idempotently. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->schema();
        $schema->enableForeignKeyConstraints();

        if (! $schema->hasTable('tenant_test_entries')) {
            $schema->create('tenant_test_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable($this->mixedEntries);
                if ($this->mixedEntries) {
                    $table->string('ownership_key');
                }
                $table->string('entry_key');
                $table->string('locale', 35);
                $table->string('name')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(
                    [$this->mixedEntries ? 'ownership_key' : 'tenant_id', 'entry_key', 'locale'],
                    'tenant_entries_group_locale_unique',
                );
            });
        }
        if (! $schema->hasTable('tenant_test_articles')) {
            $schema->create('tenant_test_articles', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('slug');
                $table->timestamps();
                $table->unique(['tenant_id', 'id'], 'tenant_articles_tenant_id_unique');
                $table->unique(['tenant_id', 'slug']);
            });
        }
        if (! $schema->hasTable('tenant_test_article_translations')) {
            $schema->create('tenant_test_article_translations', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('article_id');
                $table->string('locale', 35);
                $table->string('name')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'article_id', 'locale'], 'tenant_article_locale_unique');
                $table->foreign(['tenant_id', 'article_id'])->references(['tenant_id', 'id'])->on('tenant_test_articles')->cascadeOnDelete();
            });
        }
    }

    /** Verify this new fixture has no legacy rows to backfill. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        foreach (['tenant_test_entries', 'tenant_test_articles', 'tenant_test_article_translations'] as $table) {
            if ($this->connection()->table($table)->exists()) {
                throw new RuntimeException('Translation fixture adoption requires initially empty tables.');
            }
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Inspect real columns, unique indexes, and the composite canonical-parent constraint. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->schema();
        $errors = [];
        $contracts = [
            'tenant_test_entries' => [['id', 'tenant_id', 'entry_key', 'locale', 'name', 'created_at', 'updated_at', 'deleted_at'], [$this->mixedEntries ? 'ownership_key' : 'tenant_id', 'entry_key', 'locale']],
            'tenant_test_articles' => [['id', 'tenant_id', 'slug', 'created_at', 'updated_at'], ['tenant_id', 'id']],
            'tenant_test_article_translations' => [['id', 'tenant_id', 'article_id', 'locale', 'name', 'created_at', 'updated_at'], ['tenant_id', 'article_id', 'locale']],
        ];
        if ($this->mixedEntries) {
            $contracts['tenant_test_entries'][0][] = 'ownership_key';
        }
        foreach ($contracts as $table => [$columns, $unique]) {
            if (! $schema->hasColumns($table, $columns) || ! $schema->hasIndex($table, $unique, 'unique')) {
                $errors[] = $table.'.schema';
            }
            $ownership = array_values(array_filter($schema->getColumns($table), static fn (array $column): bool => $column['name'] === 'tenant_id'));
            $nullable = $this->mixedEntries && $table === 'tenant_test_entries';
            if (count($ownership) !== 1 || $ownership[0]['nullable'] !== $nullable) {
                $errors[] = $table.'.ownership';
            }
        }
        $foreign = array_filter($schema->getForeignKeys('tenant_test_article_translations'), static fn (array $key): bool => $key['columns'] === ['tenant_id', 'article_id'] && $key['foreign_table'] === 'tenant_test_articles' && $key['foreign_columns'] === ['tenant_id', 'id'] && strtolower($key['on_delete']) === 'cascade');
        if ($foreign === []) {
            $errors[] = 'translation_parent_constraint';
        }

        return new TenantVerification($errors);
    }

    /** Final constraints were created on empty storage during preparation. */
    public function activate(TenantAdoptionPlan $plan): void {}

    /** Resolve the fixture models' actual storage connection. */
    private function connection(): Connection
    {
        return (new TenantSelfEntry)->getConnection();
    }

    /** Resolve the fixture models' actual schema builder. */
    private function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}
