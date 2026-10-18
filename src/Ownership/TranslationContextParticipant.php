<?php

declare(strict_types=1);

namespace Nvl\Translatable\Ownership;

use Closure;
use Illuminate\Support\Facades\Context;
use Nvl\Support\Ownership\Contracts\OwnershipContextParticipant;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Translatable\Services\ContentLocale;

/** Restores explicit request locale state without changing the user's choice at scope entry. @internal */
final readonly class TranslationContextParticipant implements OwnershipContextParticipant
{
    /** Preserve exact presence as well as values across nested owner callbacks. */
    public function enter(OwnershipSnapshot $next): Closure
    {
        $previous = [];
        foreach ([ContentLocale::CONTEXT_KEY, ContentLocale::EXPLICIT_CONTEXT_KEY] as $key) {
            $previous[$key] = ['present' => Context::has($key), 'value' => Context::get($key)];
        }

        return static function () use ($previous): void {
            foreach ($previous as $key => $state) {
                if ($state['present']) {
                    Context::add($key, $state['value']);
                } else {
                    Context::forget($key);
                }
            }
        };
    }
}
