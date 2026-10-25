<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Authorizes access to registered translation resources.
 *
 * @api
 */
interface TranslationResourceAuthorizer
{
    /**
     * Determine whether an actor may perform an ability.
     */
    public function allows(
        Authenticatable|SystemActor $actor,
        TranslationResourceAbility $ability,
        TranslationResourceDefinition $resource,
        ?Model $record = null,
    ): bool;
}
