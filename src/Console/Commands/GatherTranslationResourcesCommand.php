<?php

declare(strict_types=1);

namespace Nvl\Translatable\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationResourceRecordData;
use Nvl\Translatable\Data\TranslationResourceSummaryData;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\TranslationResourceQuery;

/**
 * Lists registered translation resources or gathers their normalized records.
 */
final class GatherTranslationResourcesCommand extends Command
{
    protected $signature = 'nvl:translatable:gather
        {resource? : Registered resource key}
        {--search= : Search registered base columns}
        {--missing= : Only records missing this locale}
        {--page=1 : Page number}
        {--per-page=100 : Records per page, bounded by the resource definition}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Gather registered translatable resources from one central catalog';

    /**
     * Execute the gathering command.
     */
    public function handle(
        TranslationResourceRegistry $resources,
        TranslationResourceGatherer $gatherer,
    ): int {
        $resource = $this->argument('resource');
        $actor = TranslationActorData::system('console');

        if (! is_string($resource) || $resource === '') {
            return $this->renderSummaries($gatherer, $actor);
        }

        $definition = $resources->get($resource);

        $paginator = $gatherer->gather(
            $resource,
            $actor,
            new TranslationResourceQuery(
                search: $this->stringOption('search'),
                missingLocale: $this->stringOption('missing'),
                page: max(1, (int) $this->option('page')),
                perPage: min(
                    $definition->maximumPageSize,
                    500,
                    max(1, (int) $this->option('per-page')),
                ),
            ),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'resource' => $definition->metadata([]),
                'data' => array_map(
                    static fn (TranslationResourceRecordData $record): array => $record->toArray(),
                    $paginator->items(),
                ),
                'pagination' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Label', 'Translated locales', 'Missing locales'],
            collect($paginator->items())
                ->map(static fn (TranslationResourceRecordData $record): array => [
                    (string) $record->id,
                    $record->label,
                    implode(', ', $record->translatedLocales),
                    implode(', ', $record->missingLocales),
                ])
                ->all(),
        );
        $this->info("Page {$paginator->currentPage()} of {$paginator->lastPage()} ({$paginator->total()} records).");

        return self::SUCCESS;
    }

    /**
     * Render resource coverage summaries.
     */
    private function renderSummaries(
        TranslationResourceGatherer $gatherer,
        TranslationActorData $actor,
    ): int {
        $summaries = $gatherer->summaries($actor);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                array_map(
                    static fn (TranslationResourceSummaryData $summary): array => $summary->toArray(),
                    $summaries,
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Resource', 'Label', 'Model', 'Fields', 'Records'],
            array_map(
                static fn (TranslationResourceSummaryData $summary): array => [
                    $summary->key,
                    $summary->label,
                    $summary->model,
                    implode(', ', $summary->fields),
                    $summary->total,
                ],
                $summaries,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Return one non-empty string option.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
