<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__, 2).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TenancyTestCase');
}

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Services\TranslationOwnership;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\TenantArticle;
use Nvl\Translatable\Tests\Support\TenantArticleTranslation;
use Nvl\Translatable\Tests\Support\TenantMixedTranslationOwner;
use Nvl\Translatable\Tests\Support\TenantPolymorphicTranslationChild;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;

/** Register supplemental native roots before the immutable graph is adopted. */
function registerTranslationPartitionFixtures(bool $multipleParents): void
{
    $resources = app(OwnershipResourceRegistry::class);
    $resources->register(new OwnershipResourceDefinition(
        'mixed.owners', 'mixed-test', TenantMixedTranslationOwner::class,
        TenantMixedTranslationOwner::ownershipDefinition(), allowsCentral: true,
    ));
    $resources->register(new OwnershipResourceDefinition(
        'mixed.children', 'mixed-test', TenantPolymorphicTranslationChild::class,
        TenantPolymorphicTranslationChild::ownershipDefinition(),
        allowsCentral: true,
        parentResources: $multipleParents ? ['mixed.owners', 'test.articles'] : ['mixed.owners'],
    ));
    $adapter = new class(app(OwnershipSchema::class)) implements OwnershipAdoptionAdapter
    {
        public function __construct(private readonly OwnershipSchema $ownershipSchema) {}

        public function resources(): array
        {
            return ['mixed.owners', 'mixed.children'];
        }

        public function prepare(OwnershipAdoptionPlan $plan): void
        {
            if (! Schema::hasTable('tenant_test_mixed_owners')) {
                Schema::create('tenant_test_mixed_owners', static function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->foreignUuid('tenant_id')->nullable()->constrained('tenant_test_owners');
                });
            }
            if (! Schema::hasTable('tenant_test_polymorphic_children')) {
                Schema::create('tenant_test_polymorphic_children', static function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->string('owner_type');
                    $table->uuid('owner_id');
                    $table->index(['owner_type', 'owner_id']);
                });
            }
            foreach ($this->resources() as $resource) {
                $this->ownershipSchema->prepare($resource);
            }
        }

        public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
        {
            expect(DB::table('tenant_test_mixed_owners')->exists())->toBeFalse();
            expect(DB::table('tenant_test_polymorphic_children')->exists())->toBeFalse();

            return new OwnershipBackfillResult(null, 0);
        }

        public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
        {
            foreach ($this->resources() as $resource) {
                $this->ownershipSchema->verify($resource);
            }

            return new OwnershipVerification([]);
        }

        public function activate(OwnershipAdoptionPlan $plan): void
        {
            foreach ($this->resources() as $resource) {
                $this->ownershipSchema->constrain($resource);
            }
        }
    };
    app()->instance($adapter::class, $adapter);
    app(OwnershipAdoptionRegistry::class)->register('mixed-fixtures', $adapter::class);
    app(MaintenanceMode::class)->activate([]);
}

/** Create one root using only the current native writer assignment. */
function createMixedTranslationRoot(): TenantMixedTranslationOwner
{
    return app(OwnershipWriter::class)->create(
        TenantMixedTranslationOwner::class,
        static function (OwnershipAssignment $assignment): TenantMixedTranslationOwner {
            $owner = new TenantMixedTranslationOwner;
            $owner->forceFill($assignment->attributes());
            $owner->save();

            return $owner;
        },
    );
}

/** Create a polymorphic child with its persisted parent as the sole authority. */
function createMixedTranslationChild(TenantMixedTranslationOwner|TenantArticle $parent): TenantPolymorphicTranslationChild
{
    return app(OwnershipWriter::class)->create(
        TenantPolymorphicTranslationChild::class,
        static function (OwnershipAssignment $assignment) use ($parent): TenantPolymorphicTranslationChild {
            $child = new TenantPolymorphicTranslationChild;
            $child->forceFill([
                ...$assignment->attributes(),
                'owner_type' => $parent::class,
                'owner_id' => $parent->getKey(),
            ]);
            $child->save();

            return $child;
        },
    );
}

it('selects the declared native partition and rejects forged polymorphic parent facts', function (bool $central): void {
    registerTranslationPartitionFixtures(false);
    $scenario = TenantTranslationScenario::install(adoptionPackages: ['mixed-fixtures']);
    $callback = static function (): void {
        $owner = createMixedTranslationRoot();
        $child = createMixedTranslationChild($owner);
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'mixed.children');
        $ownership = app(TranslationOwnership::class);
        $key = $ownership->partitionKey($child, $definition);
        expect($ownership->partitionColumns($definition))->toBe(['owner_scope_key']);
        expect($ownership->childAttributes($child, $definition))->toHaveKey('owner_scope_key');
        $child->owner_type = 'unregistered';
        $child->owner_id = TenantTranslationScenario::B;
        expect(fn () => $ownership->partitionKey($child, $definition))->toThrow(OwnershipViolation::class);
        expect($key)->toHaveLength(64);
    };
    if ($central) {
        app(OwnershipRunner::class)->central('translation-maintain', OwnershipActor::query()->firstOrFail(), $callback);
    } else {
        $scenario->run($scenario::A, $callback);
    }
})->with([false, true]);

it('accepts only finite registered native polymorphic parent resources', function (): void {
    registerTranslationPartitionFixtures(true);
    $scenario = TenantTranslationScenario::install(adoptionPackages: ['mixed-fixtures']);
    $article = $scenario->article($scenario::A, 'registered-parent', []);
    $scenario->run($scenario::A, static function () use ($article): void {
        $child = createMixedTranslationChild($article);
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'mixed.children');
        expect(app(TranslationOwnership::class)->partitionColumns($definition))->toBe(['owner_scope_key']);
        expect(app(TranslationOwnership::class)->partitionKey($child, $definition))->toHaveLength(64);
        $child->owner_type = 'unregistered';
        expect(fn () => app(TranslationOwnership::class)->childAttributes($child, $definition))->toThrow(OwnershipViolation::class);
    });
});

it('rejects changed canonical polymorphic parent identity when locking', function (string $change): void {
    registerTranslationPartitionFixtures(false);
    $scenario = TenantTranslationScenario::install(adoptionPackages: ['mixed-fixtures']);
    $scenario->run($scenario::A, static function () use ($change): void {
        $owner = createMixedTranslationRoot();
        $other = createMixedTranslationRoot();
        $child = createMixedTranslationChild($owner);
        $definition = new RelatedTranslationDefinition(TenantArticleTranslation::class, ['name'], ownershipResource: 'mixed.children');
        $ownership = app(TranslationOwnership::class);
        $child->getConnection()->transaction(static function () use ($child, $other, $change, $definition, $ownership): void {
            expect($ownership->lockOwner($child, $definition)->getKey())->toBe($child->getKey());
            if ($change === 'persisted parent') {
                expect(fn () => DB::table($child->getTable())->where('id', $child->id)->update(['owner_id' => $other->id]))
                    ->toThrow(QueryException::class);
                expect($ownership->lockOwner($child, $definition)->getKey())->toBe($child->getKey());
            } elseif ($change === 'dirty type') {
                $child->owner_type = 'unregistered';
                expect(fn () => $ownership->lockOwner($child, $definition))->toThrow(OwnershipViolation::class);
            } else {
                $child->owner_id = $other->id;
                expect(fn () => $ownership->lockOwner($child, $definition))->toThrow(OwnershipViolation::class);
            }
        });
    });
})->with(['dirty parent', 'dirty type', 'persisted parent']);
