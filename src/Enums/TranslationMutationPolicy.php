<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Controls whether the central resource API may write translation rows directly.
 *
 * @api
 */
enum TranslationMutationPolicy: string
{
    case Direct = 'direct';
    case OwningServiceOnly = 'owning-service-only';
}
