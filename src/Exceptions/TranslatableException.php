<?php

declare(strict_types=1);

namespace Nvl\Translatable\Exceptions;

use Exception;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Nvl\Translatable\Enums\TranslatableResponseCode;

/**
 * @api
 * Base exception for translation configuration and runtime invariant failures.
 */
class TranslatableException extends Exception implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            InvalidLocaleException::class => new ExceptionResponse('translatable', TranslatableResponseCode::InvalidLocale, 422),
            InvalidTranslatableFieldException::class => new ExceptionResponse('translatable', TranslatableResponseCode::InvalidTranslatableField, 422),
            default => new ExceptionResponse('translatable', TranslatableResponseCode::OperationFailed),
        };
    }
}
