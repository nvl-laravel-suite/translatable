<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/** Process-local maintenance state for the standalone translation consumer. */
final class TenantTranslationFixtureMaintenanceMode implements MaintenanceMode
{
    private bool $enabled = false;

    /** @var array<string, mixed> */
    private array $payload = [];

    /**
     * Start fixture maintenance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function activate(array $payload): void
    {
        $this->enabled = true;
        $this->payload = $payload;
    }

    /** End fixture maintenance. */
    public function deactivate(): void
    {
        $this->enabled = false;
        $this->payload = [];
    }

    /** Report whether fixture maintenance is active. */
    public function active(): bool
    {
        return $this->enabled;
    }

    /**
     * Return the current maintenance payload.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->payload;
    }
}
