<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Nvl\Translatable\Data\DeleteTranslationLocaleData;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationDeleteResultData;

/**
 * Defines the consumer-facing DeleteTranslationResourceLocaleAction workflow.
 *
 * @api
 */
interface DeleteTranslationResourceLocaleContract
{
    /**
     * Delete one locale row from a registered owner.
     */
    public function execute(
        string $resourceKey,
        int|string $id,
        DeleteTranslationLocaleData $deletion,
        TranslationActorData $actor,
    ): TranslationDeleteResultData;
}
