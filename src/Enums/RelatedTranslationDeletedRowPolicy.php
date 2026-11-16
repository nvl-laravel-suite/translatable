<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Controls how related writers handle soft-deleted locale rows.
 *
 * @api
 */
enum RelatedTranslationDeletedRowPolicy: string
{
    /** Keep history and reject when a deleted row blocks the unique locale key. */
    case Reject = 'reject';

    /** Restore the exact row when only one deleted locale row exists. */
    case Restore = 'restore';
}
