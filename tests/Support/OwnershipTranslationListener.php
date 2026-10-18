<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Translatable\Events\TranslationResourceSynced;

/** Observes real native queued-listener restoration without carrying a model or principal object. */
final class OwnershipTranslationListener implements ShouldQueue
{
    /** @var list<string|null> */
    public static array $owners = [];

    public function __construct(private readonly OwnershipContext $context) {}

    public function handle(TranslationResourceSynced $event): void
    {
        self::$owners[] = $this->context->snapshot()->owner?->key;
    }
}
