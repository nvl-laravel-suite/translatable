<?php

declare(strict_types=1);

namespace Nvl\Translatable\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable actor identity carried through authorization and events.
 */
#[TypeScript]
final class TranslationActorData extends Data
{
    /**
     * Create an actor identity.
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $id,
        public readonly bool $system = false,
    ) {}

    /**
     * Create the trusted identity used by commands and internal automation.
     */
    public static function system(string $type = 'system'): self
    {
        return new self($type, null, true);
    }
}
