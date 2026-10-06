<?php

declare(strict_types=1);

namespace Nvl\Translatable\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Announces a committed patch or replacement of a registered resource's locale rows.
 *
 * @api
 */
final readonly class TranslationResourceSynced implements DomainEvent
{
    /**
     * Create the committed translation synchronization event.
     *
     * @param  list<string>  $locales
     */
    public function __construct(
        public string $resource,
        public string $ownerType,
        public int|string $ownerId,
        public array $locales,
        public TranslationSyncMode $mode,
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
