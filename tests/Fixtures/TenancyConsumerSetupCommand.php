<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationProbeJob;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use RuntimeException;

/** Creates isolated fixture storage, adoption state, seed rows, and worker probes. */
final class TenancyConsumerSetupCommand extends Command
{
    protected $signature = 'tenancy-fixture:setup {--queue=} {--inspect=} {--group=same}';

    protected $description = 'Prepare or inspect the isolated tenancy translation fixture.';

    /** Run setup, queue publication, or a read-only version inspection. */
    public function handle(
        TenantAdoptionCoordinator $coordinator,
        TenantRunner $runner,
        TenantBoundary $boundary,
        MaintenanceMode $maintenance,
        TenantContext $context,
        TranslationResourceVersioner $versioner,
    ): int {
        $inspect = $this->stringOption('inspect');
        if ($inspect !== null) {
            return $this->inspect($inspect, $this->stringOption('group') ?? 'same', $runner, $versioner);
        }

        $this->createInfrastructure();
        $maintenance->activate([]);
        try {
            $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
            $plan = $coordinator->prepare(['translation-fixtures'], [], $operation);
            $done = false;
            for ($batch = 0; $batch < 20 && ! $done; $batch++) {
                $done = $coordinator->backfill($plan, 100, $operation);
            }
            if (! $done || ! $coordinator->verify($plan)->passed()) {
                throw new RuntimeException('Translation fixture adoption did not verify.');
            }
            $coordinator->activate($plan, $operation);
        } finally {
            $maintenance->deactivate();
        }

        $a = $this->seed($runner, $boundary, TenantTranslationScenario::A, 'same', 'en', 'A fallback');
        $b = $this->seed($runner, $boundary, TenantTranslationScenario::B, 'same', 'bg', 'B requested');
        $queue = $this->stringOption('queue');
        if ($queue !== null) {
            $this->publishProbes($queue, $this->modelKey($a), $this->modelKey($b), $runner, $context);
        }

        $this->line('driver='.DB::connection()->getDriverName().' setup=ok'.($queue !== null ? ' queue='.$queue : ''));

        return self::SUCCESS;
    }

    /** Create core, queue, failure, and probe tables on the configured disposable connection. */
    private function createInfrastructure(): void
    {
        $root = getenv('NVL_TEST_SUITE_ROOT');
        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The fixture requires NVL_TEST_SUITE_ROOT.');
        }
        $migration = require $root.'/packages/nvl/tenancy/database/migrations/tenancy/2026_09_16_000001_create_tenancy_core_tables.php';
        if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
            throw new RuntimeException('The tenancy core migration is invalid.');
        }
        call_user_func([$migration, 'up']);
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $schema->create('tenant_probe_results', static function (Blueprint $table): void {
            $table->string('result_key')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->text('value')->nullable();
        });
    }

    /** Persist one canonical seed row inside its tenant boundary. */
    private function seed(
        TenantRunner $runner,
        TenantBoundary $boundary,
        string $tenant,
        string $group,
        string $locale,
        string $name,
    ): TenantSelfEntry {
        return $runner->run(new TenantId($tenant), static function () use ($boundary, $group, $locale, $name): TenantSelfEntry {
            $entry = new TenantSelfEntry(['entry_key' => $group, 'locale' => $locale, 'name' => $name]);
            $entry->forceFill($boundary->attributes('test.entries'));
            $entry->saveOrFail();

            return $entry;
        });
    }

    /** Publish valid probes and corrupt only the final stored scalar envelope. */
    private function publishProbes(
        string $queue,
        string $recordA,
        string $recordB,
        TenantRunner $runner,
        TenantContext $context,
    ): void {
        $uncaptured = new TenantTranslationProbeJob(
            $recordA,
            'en',
            'missing-context',
            TenantJobEnvelope::capture($context),
        );
        try {
            Bus::dispatch($uncaptured->onConnection('database')->onQueue($queue));
            throw new RuntimeException('Missing tenant context unexpectedly dispatched.');
        } catch (TenantBoundaryViolation) {
        }

        $this->dispatchProbe($runner, TenantTranslationScenario::A, $recordA, 'en', 'a-en', $queue);
        $this->dispatchProbe($runner, TenantTranslationScenario::B, $recordB, 'bg', 'b-bg', $queue);
        $this->dispatchProbe($runner, TenantTranslationScenario::A, $recordA, 'en', 'failure-a-en', $queue, true);
        $this->dispatchProbe($runner, TenantTranslationScenario::A, $recordA, 'en', 'corrupt-before-read', $queue);

        $connection = DB::connection();
        $job = $connection->table('jobs')->where('queue', $queue)->orderByDesc('id')->first();
        if (! is_object($job) || ! is_string($job->payload ?? null)) {
            throw new RuntimeException('The final serialized probe was not stored.');
        }
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('The final serialized probe payload is invalid.');
        }
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_array($data['nvl_tenancy'] ?? null)) {
            throw new RuntimeException('The final serialized probe has no tenant envelope.');
        }
        $data['nvl_tenancy']['tenant_id'] = TenantTranslationScenario::B;
        $payload['data'] = $data;
        $connection->table('jobs')->where('id', $job->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Dispatch one scalar probe from an admitted producer tenant. */
    private function dispatchProbe(
        TenantRunner $runner,
        string $tenant,
        string $record,
        string $locale,
        string $result,
        string $queue,
        bool $throw = false,
    ): void {
        $runner->run(new TenantId($tenant), static function () use ($record, $locale, $result, $queue, $throw): void {
            $job = new TenantTranslationProbeJob(
                $record,
                $locale,
                $result,
                TenantJobEnvelope::capture(app(TenantContext::class)),
                $throw,
            );
            Bus::dispatch($job->onConnection('database')->onQueue($queue));
        });
    }

    /** Print one canonical logical version without mutating fixture state. */
    private function inspect(
        string $tenant,
        string $group,
        TenantRunner $runner,
        TranslationResourceVersioner $versioner,
    ): int {
        $result = $runner->run(new TenantId($tenant), static function () use ($group, $versioner): array {
            $owner = TenantSelfEntry::query()->locale('en')->where('entry_key', $group)->firstOrFail();

            return [
                'driver' => $owner->getConnection()->getDriverName(),
                'locale_count' => $owner->getAllTranslations()->count(),
                'version' => $versioner->version($owner),
            ];
        });
        $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** Read one optional scalar command option. */
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if ($value === null || $value === false) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The [{$key}] fixture option must be a non-empty string.");
        }

        return $value;
    }

    /** Return one persisted scalar fixture key. */
    private function modelKey(TenantSelfEntry $entry): string
    {
        $key = $entry->getKey();
        if (! is_string($key)) {
            throw new RuntimeException('The fixture entry key is invalid.');
        }

        return $key;
    }
}
