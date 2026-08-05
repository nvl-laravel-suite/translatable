<?php

declare(strict_types=1);

namespace Nvl\Translatable\Events;

use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Announces a committed patch or replacement of a registered resource's locale rows.
 */
final readonly class TranslationResourceSynced
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
    ) {}
}
