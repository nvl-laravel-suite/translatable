<?php

declare(strict_types=1);

namespace Nvl\Translatable\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Translatable\Data\TranslationActorData;

/**
 * Announces a bounded read from the central translation registry.
 *
 * @api
 */
final readonly class TranslationResourcesGathered implements DomainEvent
{
    /**
     * Create a gather event.
     */
    public function __construct(
        public string $resource,
        public int $page,
        public int $count,
        public TranslationActorData $actor,
        public int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
