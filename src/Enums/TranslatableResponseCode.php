<?php

declare(strict_types=1);

namespace Nvl\Translatable\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response discriminators for Translatable.
 * @api
 */
enum TranslatableResponseCode: string implements ResponseCode
{
    case OperationFailed = 'operation_failed';
    case InvalidLocale = 'invalid_locale';
    case InvalidTranslatableField = 'invalid_translatable_field';
    case InvalidTranslationResource = 'invalid_translation_resource';
}
