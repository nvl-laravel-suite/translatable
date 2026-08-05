<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Authorizes access to registered translation resources.
 */
interface TranslationResourceAuthorizer
{
    /**
     * Determine whether an actor may perform an ability.
     */
    public function allows(
        TranslationActorData $actor,
        TranslationResourceAbility $ability,
        TranslationResourceDefinition $resource,
        ?Model $record = null,
    ): bool;
}
