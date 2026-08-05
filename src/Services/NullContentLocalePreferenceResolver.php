<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Nvl\Translatable\Contracts\ContentLocalePreferenceResolver;

/**
 * Provides the default no-op application preference integration.
 */
final class NullContentLocalePreferenceResolver implements ContentLocalePreferenceResolver
{
    /**
     * Resolve no application-owned preference.
     */
    public function resolve(): ?string
    {
        return null;
    }
}
