<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Identifies how a model persists the rows representing its translations.
 *
 * @api
 */
enum TranslationStorageStrategy: string
{
    case Related = 'related';
    case Self = 'self';
}
