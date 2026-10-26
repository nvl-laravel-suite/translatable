<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Exceptions\TranslationResourceException;

/**
 * Extracts bounded attribution facts from an actor admitted by a resource policy.
 *
 * @internal
 */
final class TranslationEventActor
{
    /** @return array{type: string, id: int|string} */
    public static function identity(Authenticatable|SystemActor $actor): array
    {
        if ($actor instanceof SystemActor) {
            return ['type' => SystemActor::class, 'id' => $actor->name];
        }

        $id = $actor->getAuthIdentifier();
        if ((! is_int($id) && ! is_string($id)) || $id === '') {
            throw TranslationResourceException::invalid('Event attribution requires a persisted scalar actor identity.');
        }
        if ($actor instanceof Model && (! $actor->exists || $actor->isDirty($actor->getAuthIdentifierName()))) {
            throw TranslationResourceException::invalid('Event attribution requires a persisted scalar actor identity.');
        }

        return [
            'type' => $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            'id' => $id,
        ];
    }
}
