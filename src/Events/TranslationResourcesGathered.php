<?php

declare(strict_types=1);

namespace Nvl\Translatable\Events;

use Nvl\Translatable\Data\TranslationActorData;

/**
 * Announces a bounded read from the central translation registry.
 */
final readonly class TranslationResourcesGathered
{
    /**
     * Create a gather event.
     */
    public function __construct(
        public string $resource,
        public int $page,
        public int $count,
        public TranslationActorData $actor,
    ) {}
}
