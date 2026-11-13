<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Creates real native schemas and registers the same concrete graph that consumers declare. */
final readonly class OwnershipTranslationScenario
{
    public static function createTables(): void
    {
        $schema = DB::getSchemaBuilder();
        foreach (['translation_ownership_records', 'translation_ownership_self_records'] as $name) {
            $schema->create($name, static function (Blueprint $table) use ($name): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->foreign('company_id')->references('id')->on('translation_ownership_companies');
                if ($name === 'translation_ownership_self_records') {
                    $table->string('entry_key');
                    $table->string('locale', 35);
                    $table->string('name')->nullable();
                }
            });
        }
        $schema->create('translation_ownership_rows', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('translation_ownership_records')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('name')->nullable();
            $table->unique(['record_id', 'locale']);
        });
    }

    public static function register(Container $app): void
    {
        foreach (['fixture.records' => OwnershipRelatedRecord::class, 'fixture.self' => OwnershipSelfRecord::class, 'fixture.rows' => OwnershipRelatedTranslation::class] as $key => $class) {
            $app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition($key, 'fixture', $class, $class::ownershipDefinition(), parentResource: $key === 'fixture.rows' ? 'fixture.records' : null));
        }
        $app->make(OwnershipAdoptionRegistry::class)->register('fixture', OwnershipTranslationAdapter::class);
    }
}
