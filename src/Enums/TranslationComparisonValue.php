<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Distinguishes shorthand comparison calls from an explicitly supplied null value.
 *
 * @internal
 */
enum TranslationComparisonValue
{
    case Omitted;
}
