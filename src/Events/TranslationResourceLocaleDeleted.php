<?php

declare(strict_types=1);

namespace Nvl\Translatable\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Translatable\Data\TranslationActorData;

/**
 * Announces a committed locale-row deletion from a registered translation resource.
 *
 * @api
 */
final readonly class TranslationResourceLocaleDeleted implements DomainEvent
{
    /**
     * Create the committed translation deletion event.
     */
    public function __construct(
        public string $resource,
        public string $ownerType,
        public int|string $ownerId,
        public string $locale,
        public TranslationActorData $actor,
        public string $previousVersion,
        public string $version,
        public int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
