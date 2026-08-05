<?php

declare(strict_types=1);

namespace Nvl\Translatable;

/**
 * Carries configuration and schema diagnostics for registered translation resources.
 */
final readonly class TranslationDiagnosticReport
{
    /**
     * Create a translation diagnostic report.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $errors,
        public array $warnings,
        public int $checkedResources,
    ) {}

    /**
     * Determine whether every required invariant passed.
     */
    public function isHealthy(): bool
    {
        return $this->errors === [];
    }

    /**
     * Return a JSON-safe report payload.
     *
     * @return array{
     *     healthy: bool,
     *     checkedResources: int,
     *     errors: list<string>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'checkedResources' => $this->checkedResources,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
