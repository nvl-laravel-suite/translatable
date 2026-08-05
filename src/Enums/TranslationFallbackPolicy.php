<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Defines how a missing requested locale may resolve to another locale.
 */
enum TranslationFallbackPolicy: string
{
    case ExactOnly = 'exact-only';
    case Configured = 'configured';
    case AnyAvailable = 'any-available';
}
