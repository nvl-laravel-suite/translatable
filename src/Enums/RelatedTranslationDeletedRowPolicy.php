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
    /** Reject the write while a soft-deleted row holds the same owner and locale. */
    case Reject = 'reject';

    /** Restore the exact row when only one deleted locale row exists. */
    case Restore = 'restore';

    /**
     * Keep soft-deleted rows as history and add a fresh active row.
     *
     * Use only with an owner and locale unique index limited to active rows (`WHERE deleted_at IS NULL`).
     */
    case KeepHistory = 'keep_history';
}
