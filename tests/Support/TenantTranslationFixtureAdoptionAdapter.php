<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        if (! Schema::hasTable('tenant_test_entries')) {
            Schema::create('tenant_test_entries', function (Blueprint $table): void {
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
                $table->unique([$this->mixedEntries ? 'ownership_key' : 'tenant_id', 'entry_key', 'locale']);
            });
        }
        if (! Schema::hasTable('tenant_test_articles')) {
            Schema::create('tenant_test_articles', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('slug');
                $table->timestamps();
                $table->unique(['tenant_id', 'id']);
                $table->unique(['tenant_id', 'slug']);
            });
        }
        if (! Schema::hasTable('tenant_test_article_translations')) {
            Schema::create('tenant_test_article_translations', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('article_id');
                $table->string('locale', 35);
                $table->string('name')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'article_id', 'locale']);
                $table->foreign(['tenant_id', 'article_id'])->references(['tenant_id', 'id'])->on('tenant_test_articles')->cascadeOnDelete();
            });
        }
    }

    /** Verify this new fixture has no legacy rows to backfill. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        foreach (['tenant_test_entries', 'tenant_test_articles', 'tenant_test_article_translations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Translation fixture adoption requires initially empty tables.');
            }
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Inspect real columns, unique indexes, and the composite canonical-parent constraint. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
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
            if (! Schema::hasColumns($table, $columns) || ! Schema::hasIndex($table, $unique, 'unique')) {
                $errors[] = $table.'.schema';
            }
            $ownership = array_values(array_filter(Schema::getColumns($table), static fn (array $column): bool => $column['name'] === 'tenant_id'));
            $nullable = $this->mixedEntries && $table === 'tenant_test_entries';
            if (count($ownership) !== 1 || $ownership[0]['nullable'] !== $nullable) {
                $errors[] = $table.'.ownership';
            }
        }
        $foreign = array_filter(Schema::getForeignKeys('tenant_test_article_translations'), static fn (array $key): bool => $key['columns'] === ['tenant_id', 'article_id'] && $key['foreign_table'] === 'tenant_test_articles' && $key['foreign_columns'] === ['tenant_id', 'id'] && strtolower($key['on_delete']) === 'cascade');
        if ($foreign === []) {
            $errors[] = 'translation_parent_constraint';
        }

        return new TenantVerification($errors);
    }

    /** Final constraints were created on empty storage during preparation. */
    public function activate(TenantAdoptionPlan $plan): void {}
}
