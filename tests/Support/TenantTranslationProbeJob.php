<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Translatable\Services\ContentLocale;
use RuntimeException;

/** Reads one canonical translation after the worker restores its captured tenant. */
final class TenantTranslationProbeJob implements OwnershipQueuedJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Create a scalar-only translation probe command. */
    public function __construct(
        public readonly string $recordId,
        public readonly string $locale,
        public readonly string $resultKey,
        public readonly OwnershipJobEnvelope $envelope,
        public readonly bool $throwAfterRead = false,
    ) {}

    /** Return the immutable producer tenant envelope. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }

    /** Resolve the row canonically, persist the observed value, and optionally fail. */
    public function handle(OwnershipContext $context, ContentLocale $contentLocale): void
    {
        $contentLocale->set($this->locale);
        $entry = TenantSelfEntry::query()->whereKey($this->recordId)->firstOrFail();
        $value = $entry->translated('name', $this->locale);

        DB::table('tenant_probe_results')->insert([
            'result_key' => $this->resultKey,
            'tenant_id' => $context->requireOwner()->key,
            'value' => is_scalar($value) ? (string) $value : null,
        ]);

        if ($this->throwAfterRead) {
            throw new RuntimeException('translation probe');
        }
    }
}
