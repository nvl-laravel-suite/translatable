<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;

/** Activates empty fixture resources with actual parent and natural-identity constraints. */
final readonly class OwnershipTranslationAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(private OwnershipSchema $schema) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['fixture.records', 'fixture.self', 'fixture.rows'];
    }

    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $resource) {
            $this->schema->prepare($resource);
        }
        $schema = DB::getSchemaBuilder();
        if (! $schema->hasIndex('translation_ownership_self_records', 'translation_ownership_self_identity')) {
            $schema->table('translation_ownership_self_records', static fn (Blueprint $table) => $table->unique(['owner_scope_key', 'entry_key', 'locale'], 'translation_ownership_self_identity'));
        }
    }

    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return new OwnershipBackfillResult(null, 0);
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $resource) {
            $this->schema->verify($resource);
        }

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $resource) {
            $this->schema->constrain($resource);
        }
    }
}
