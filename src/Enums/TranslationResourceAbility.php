<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

/**
 * Abilities enforced by the central translation registry.
 *
 * @api
 */
enum TranslationResourceAbility: string
{
    case List = 'list';
    case View = 'view';
    case Synchronize = 'synchronize';
    case Delete = 'delete';
    case Report = 'report';
}
