<?php

declare(strict_types=1);

namespace Nvl\Translatable\Exceptions;

use InvalidArgumentException;

/**
 * Reports invalid centralized translation resource registration or mutation.
 */
final class TranslationResourceException extends InvalidArgumentException
{
    /**
     * Create an exception for an unknown resource key.
     *
     * @param  list<string>  $registered
     */
    public static function unknown(string $key, array $registered): self
    {
        $available = $registered === [] ? 'none' : implode(', ', $registered);

        return new self("Translation resource [{$key}] is not registered. Available resources: {$available}.");
    }

    /**
     * Create an exception for an invalid resource definition.
     */
    public static function invalid(string $message): self
    {
        return new self($message);
    }

    /**
     * Create an exception for a conflicting duplicate registry key.
     */
    public static function duplicate(string $key): self
    {
        return new self("Translation resource [{$key}] is already registered with a different definition.");
    }

    /**
     * Create an exception for a resource whose owner or translation table is unavailable.
     */
    public static function unavailable(string $key, string $ownerTable, string $translationTable): self
    {
        return new self(
            "Translation resource [{$key}] is unavailable until tables [{$ownerTable}] and [{$translationTable}] exist.",
        );
    }

    /**
     * Create an exception for undeclared translated fields.
     *
     * @param  list<string>  $fields
     */
    public static function undeclaredFields(string $resource, string $locale, array $fields): self
    {
        return new self(
            "Translation resource [{$resource}] received undeclared fields for [{$locale}]: ".implode(', ', $fields).'.',
        );
    }

    /**
     * Create an exception for locale keys that normalize to the same value.
     */
    public static function duplicateLocale(string $resource, string $locale): self
    {
        return new self(
            "Translation resource [{$resource}] contains duplicate normalized locale [{$locale}].",
        );
    }

    /**
     * Create an exception for a denied registry operation.
     */
    public static function unauthorized(string $resource, string $ability): self
    {
        return new self(
            "Translation resource [{$resource}] does not authorize [{$ability}] for this actor.",
        );
    }

    /**
     * Create an exception for a stale optimistic-concurrency token.
     */
    public static function stale(string $resource): self
    {
        return new self(
            "Translation resource [{$resource}] changed after it was read. Gather it again before writing.",
        );
    }

    /**
     * Create an exception when a package-owned domain action must perform the mutation.
     */
    public static function requiresDomainAction(string $resource): self
    {
        return new self(
            "Translation resource [{$resource}] must be mutated through its package domain action.",
        );
    }
}
