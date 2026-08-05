<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

/**
 * Resolves an optional content-locale preference from an application-owned source.
 */
interface ContentLocalePreferenceResolver
{
    /**
     * Resolve the preferred content locale when one is available.
     */
    public function resolve(): ?string;
}
