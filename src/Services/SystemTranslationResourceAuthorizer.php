<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Secure default that only permits explicitly identified system operations.
 */
final class SystemTranslationResourceAuthorizer implements TranslationResourceAuthorizer
{
    /**
     * Allow trusted command and automation identities only.
     */
    public function allows(
        TranslationActorData $actor,
        TranslationResourceAbility $ability,
        TranslationResourceDefinition $resource,
        ?Model $record = null,
    ): bool {
        return $actor->system;
    }
}
