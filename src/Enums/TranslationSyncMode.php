<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Defines whether a translation mutation preserves or removes omitted locales.
 */
enum TranslationSyncMode: string
{
    case Patch = 'patch';
    case Replace = 'replace';
}
