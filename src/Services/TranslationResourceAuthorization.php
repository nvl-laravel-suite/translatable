<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Enforces resource-level and application-level authorization.
 */
final readonly class TranslationResourceAuthorization
{
    /**
     * Create the authorization gateway.
     */
    public function __construct(
        private TranslationResourceAuthorizer $authorizer,
    ) {}

    /**
     * Throw when an operation is not permitted.
     */
    public function authorize(
        TranslationActorData $actor,
        TranslationResourceAbility $ability,
        TranslationResourceDefinition $resource,
        ?Model $record = null,
    ): void {
        $resourceDecision = $resource->authorize($actor, $ability, $record);
        $allowed = $resourceDecision
            ?? $this->authorizer->allows($actor, $ability, $resource, $record);

        if (! $allowed) {
            throw TranslationResourceException::unauthorized($resource->key, $ability->value);
        }
    }
}
