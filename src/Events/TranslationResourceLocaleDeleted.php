<?php

declare(strict_types=1);

namespace Nvl\Translatable\Events;

use Illuminate\Container\Container;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces a committed locale-row deletion from a registered translation resource.
 *
 * @api
 */
final readonly class TranslationResourceLocaleDeleted implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $envelope;

    /**
     * Create the committed translation deletion event.
     */
    public function __construct(
        public string $resource,
        public string $ownerType,
        public int|string $ownerId,
        public string $locale,
        public string $actorType,
        public int|string $actorId,
        public string $previousVersion,
        public string $version,
        public int $schemaVersion = 1,
    ) {
        $this->envelope = OwnershipJobEnvelope::capture(Container::getInstance()->make(OwnershipContext::class));
    }

    /** Return scalar producer admission captured before the source transaction commits. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
