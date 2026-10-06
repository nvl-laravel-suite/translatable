<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Data\TranslationMutationResultData;

/**
 * Defines the consumer-facing SyncTranslationResourceAction workflow.
 *
 * @api
 */
interface SyncTranslationResourceContract
{
    /**
     * Synchronize a registered owner's translations and return the refreshed model.
     */
    public function execute(
        string $resourceKey,
        int|string $id,
        TranslationMutationData $mutation,
        TranslationActorData $actor,
    ): TranslationMutationResultData;
}
