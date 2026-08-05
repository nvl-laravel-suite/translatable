<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Controls whether the central resource API may write translation rows directly.
 */
#[TypeScript]
enum TranslationMutationPolicy: string
{
    case Direct = 'direct';
    case DomainActionOnly = 'domain-action-only';
}
