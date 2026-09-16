<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantParentResolver;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Services\TranslationOwnership;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantMixedTranslationOwner;
use Nvl\Translatable\Tests\Support\TenantPolymorphicTranslationChild;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;

/** Adopt only these two supplemental service fixtures with a real allowlisted graph. */
function adoptTranslationPartitionFixtures(bool $heterogeneous): void
{
    $registry = app(TenantResourceRegistry::class);
    $registry->register(new TenantResourceDefinition('mixed.owners', 'mixed-test', TenantMixedTranslationOwner::class, allowsPlatformRows: true));
    $registry->register(new TenantResourceDefinition('mixed.children', 'mixed-test', TenantPolymorphicTranslationChild::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    $resolver = new class($heterogeneous) implements TenantParentResolver
    {
        /** Select the declared test graph without consulting caller model attributes. */
        public function __construct(private readonly bool $heterogeneous) {}

        /** @return array<string, class-string<Model>> */
        public function types(): array
        {
            return [TenantMixedTranslationOwner::class => TenantMixedTranslationOwner::class,
                ...($this->heterogeneous ? [TenantArticle::class => TenantArticle::class] : [])];
        }
    };
    app()->instance($resolver::class, $resolver);
    $registry->registerParentResolver('mixed.children', $resolver::class);
    $adapter = new class implements TenantAdoptionAdapter
    {
        /** @return list<string> */
        public function resources(): array
        {
            return ['mixed.owners', 'mixed.children'];
        }

        /** Prepare this test owner's explicit mixed and inherited schemas. */
        public function prepare(TenantAdoptionPlan $plan): void
        {
            if (! Schema::hasTable('tenant_test_mixed_owners')) {
                Schema::create('tenant_test_mixed_owners', static function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('tenant_id')->nullable();
                    $table->string('ownership_key');
                    $table->index(['ownership_key', 'id']);
                });
            }
            if (! Schema::hasTable('tenant_test_polymorphic_children')) {
                Schema::create('tenant_test_polymorphic_children', static function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('tenant_id')->nullable();
                    $table->string('owner_type');
                    $table->uuid('owner_id');
                    $table->index(['owner_type', 'owner_id']);
                });
            }
        }

        /** The supplemental test resources have no historical rows. */
        public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
        {
            expect(DB::table('tenant_test_mixed_owners')->exists())->toBeFalse();
            expect(DB::table('tenant_test_polymorphic_children')->exists())->toBeFalse();

            return new TenantBackfillResult(null, 0);
        }

        /** Inspect actual fixture schema rather than installing synthetic markers. */
        public function verify(TenantAdoptionPlan $plan): TenantVerification
        {
            return new TenantVerification(
                Schema::hasColumns('tenant_test_mixed_owners', ['id', 'tenant_id', 'ownership_key'])
                && Schema::hasIndex('tenant_test_mixed_owners', ['ownership_key', 'id'])
                && Schema::hasColumns('tenant_test_polymorphic_children', ['id', 'tenant_id', 'owner_type', 'owner_id'])
                    ? [] : ['mixed_fixture_schema'],
            );
        }

        /** The prepared fixture already has its final constraints. */
        public function activate(TenantAdoptionPlan $plan): void {}
    };
    app()->instance($adapter::class, $adapter);
    app(TenantAdoptionRegistry::class)->register('mixed-fixtures', $adapter::class);
    app(MaintenanceMode::class)->activate([]);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
    $plan = $coordinator->prepare(['mixed-fixtures'], [], $operation);
    $done = false;
    for ($batch = 0; $batch < 20 && ! $done; $batch++) {
        $done = $coordinator->backfill($plan, 100, $operation);
    }
    expect($done)->toBeTrue();
    expect($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();
}

it('selects the declared mixed partition and resolves persisted polymorphic parents', function (bool $platform): void {
    $scenario = TenantTranslationScenario::install();
    adoptTranslationPartitionFixtures(false);
    $callback = function () use ($platform): void {
        $owner = new TenantMixedTranslationOwner;
        $owner->forceFill(app(TenantBoundary::class)->attributes('mixed.owners'))->save();
        $child = new TenantPolymorphicTranslationChild;
        $child->forceFill(['tenant_id' => $owner->tenant_id, 'owner_type' => $owner::class, 'owner_id' => $owner->id])->save();
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'mixed.children');
        $ownership = app(TranslationOwnership::class);
        $key = $ownership->partitionKey($child, $definition);
        $child->owner_type = 'unregistered';
        $child->owner_id = TenantTranslationScenario::B;
        $child->tenant_id = TenantTranslationScenario::B;
        expect($ownership->partitionColumns($definition))->toBe(['ownership_key']);
        expect($ownership->childAttributes($child, $definition))->toBe([
            'tenant_id' => $platform ? null : TenantTranslationScenario::A,
            'ownership_key' => $platform ? 'platform' : 'tenant:'.TenantTranslationScenario::A,
        ]);
        expect($ownership->partitionKey($child, $definition))->toBe($key);
        DB::table($child->getTable())->where('id', $child->id)->update(['owner_type' => 'unregistered']);
        expect(fn () => $ownership->childAttributes($child, $definition))->toThrow(TenantBoundaryViolation::class);
    };
    if ($platform) {
        app(TenantRunner::class)->platform(new PlatformOperation('fixture.adoption', 'test', 'fixture'), $callback);
    } else {
        $scenario->run($scenario::A, $callback);
    }
})->with([false, true]);

it('rejects heterogeneous polymorphic root partition schemas', function (): void {
    $scenario = TenantTranslationScenario::install();
    adoptTranslationPartitionFixtures(true);
    $scenario->run($scenario::A, function (): void {
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'mixed.children');
        expect(fn () => app(TranslationOwnership::class)->partitionColumns($definition))
            ->toThrow(TenantConfigurationInvalid::class, 'consistent partition schema');
    });
});

it('rejects changed canonical polymorphic parent identity when locking', function (string $change): void {
    $scenario = TenantTranslationScenario::install();
    adoptTranslationPartitionFixtures(false);
    $scenario->run($scenario::A, function () use ($change): void {
        $owner = new TenantMixedTranslationOwner;
        $owner->forceFill(app(TenantBoundary::class)->attributes('mixed.owners'))->save();
        $other = new TenantMixedTranslationOwner;
        $other->forceFill(app(TenantBoundary::class)->attributes('mixed.owners'))->save();
        $child = new TenantPolymorphicTranslationChild;
        $child->forceFill(['tenant_id' => $owner->tenant_id, 'owner_type' => $owner::class, 'owner_id' => $owner->id])->save();
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'mixed.children');
        $ownership = app(TranslationOwnership::class);
        $child->getConnection()->transaction(function () use ($child, $other, $change, $definition, $ownership): void {
            expect($ownership->lockOwner($child, $definition)->getKey())->toBe($child->getKey());
            if ($change === 'persisted parent') {
                DB::table($child->getTable())->where('id', $child->id)->update(['owner_id' => $other->id]);
            } elseif ($change === 'dirty type') {
                $child->owner_type = 'unregistered';
            } else {
                $child->owner_id = $other->id;
            }
            expect(fn () => $ownership->lockOwner($child, $definition))->toThrow(TenantBoundaryViolation::class);
        });
    });
})->with(['dirty parent', 'dirty type', 'persisted parent']);
