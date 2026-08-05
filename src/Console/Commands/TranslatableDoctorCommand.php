<?php

declare(strict_types=1);

namespace Nvl\Translatable\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translatable\Services\TranslationDoctor;

/**
 * Verifies global translation configuration and every registered model schema.
 */
final class TranslatableDoctorCommand extends Command
{
    protected $signature = 'nvl:translatable:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Validate translatable configuration, model declarations, and database invariants';

    /**
     * Execute the translation diagnostics command.
     */
    public function handle(TranslationDoctor $doctor): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->error('Invalid --format option. Allowed values: text, json.');

            return self::FAILURE;
        }

        $report = $doctor->inspect();
        $failed = ! $report->isHealthy()
            || ((bool) $this->option('strict') && $report->warnings !== []);

        if ($format === 'json') {
            $payload = $report->toArray();
            $payload['healthy'] = ! $failed;

            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($report->errors as $error) {
            $this->error($error);
        }

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }

        if ($failed) {
            $this->newLine();
            $this->error(
                "Translation diagnostics failed for {$report->checkedResources} registered resources.",
            );

            return self::FAILURE;
        }

        $this->info(
            "Translation diagnostics passed for {$report->checkedResources} registered resources.",
        );

        return self::SUCCESS;
    }
}
