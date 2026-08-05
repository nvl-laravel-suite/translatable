<?php

declare(strict_types=1);

namespace Nvl\Translatable\Exceptions;

/**
 * Reports an attempt to access or query a field outside a model's translation definition.
 */
final class InvalidTranslatableFieldException extends TranslatableException
{
    /**
     * Create an exception for an undeclared translated field.
     *
     * @param  list<string>  $allowedFields
     */
    public static function forField(string $field, array $allowedFields): self
    {
        return new self(sprintf(
            'The field [%s] is not translatable. Allowed fields: %s.',
            $field,
            implode(', ', $allowedFields),
        ));
    }
}
