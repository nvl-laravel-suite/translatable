<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Secure default for applications without an explicit resource authorizer.
 *
 * @internal
 */
final class SystemTranslationResourceAuthorizer implements TranslationResourceAuthorizer
{
    /**
     * Require the host to authorize every resource operation explicitly.
     */
    public function allows(
        Authenticatable|SystemActor $actor,
        TranslationResourceAbility $ability,
        TranslationResourceDefinition $resource,
        ?Model $record = null,
    ): bool {
        return false;
    }
}
