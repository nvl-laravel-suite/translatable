<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

/**
 * Resolves an optional content-locale preference from an application-owned source.
 *
 * ContentLocale consults the bound resolver on every read that has no explicitly set
 * locale, so an implementation must be cheap and free of side effects.
 *
 * @api
 */
interface ContentLocalePreferenceResolver
{
    /**
     * Resolve the preferred content locale when one is available.
     */
    public function resolve(): ?string;
}
