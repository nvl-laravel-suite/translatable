<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Tests\Support\OwnershipActor;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use Nvl\Translatable\Tests\Support\TenantTranslationOwner;
use Nvl\Translatable\Tests\Support\TenantTranslationProbeJob;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use RuntimeException;

/** Creates isolated native ownership storage, adoption state, and worker probes. */
final class TenancyConsumerSetupCommand extends Command
{
    protected $signature = 'tenancy-fixture:setup {--queue=} {--inspect=} {--group=same}';

    protected $description = 'Prepare or inspect the isolated ownership translation fixture.';

    /** Run setup, queue publication, or a read-only version inspection. */
    public function handle(
        OwnershipAdoptionCoordinator $coordinator,
        OwnershipRunner $runner,
        OwnershipWriter $writer,
        MaintenanceMode $maintenance,
        OwnershipContext $context,
        TranslationResourceVersioner $versioner,
    ): int {
        $inspect = $this->stringOption('inspect');
        if ($inspect !== null) {
            return $this->inspect($inspect, $this->stringOption('group') ?? 'same', $runner, $versioner);
        }

        $this->createInfrastructure();
        $maintenance->activate([]);
        try {
            $operation = new OwnershipOperation('fixture.adoption', 'test', 'fixture');
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

        $a = $this->seed($runner, $writer, TenantTranslationScenario::A, 'same', 'en', 'A fallback');
        $b = $this->seed($runner, $writer, TenantTranslationScenario::B, 'same', 'bg', 'B requested');
        $queue = $this->stringOption('queue');
        if ($queue !== null) {
            $this->publishProbes($queue, $this->modelKey($a), $this->modelKey($b), $runner, $context);
        }

        $this->line('driver='.DB::connection()->getDriverName().' setup=ok'.($queue !== null ? ' queue='.$queue : ''));

        return self::SUCCESS;
    }

    /** Create owner, actor, native metadata, queue, and probe tables. */
    private function createInfrastructure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('tenant_test_owners', static fn (Blueprint $table) => $table->uuid('id')->primary());
        DB::table('tenant_test_owners')->insert([
            ['id' => TenantTranslationScenario::A],
            ['id' => TenantTranslationScenario::B],
        ]);
        $schema->create('translation_ownership_actors', static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        DB::table('translation_ownership_actors')->insert(['id' => 1]);

        $ownershipPath = InstalledVersions::getInstallPath('nvl/ownership')
            ?? throw new RuntimeException('The worker fixture requires the installed optional Ownership package.');
        $migration = require $ownershipPath.'/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php';
        if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
            throw new RuntimeException('The ownership adoption migration is invalid.');
        }
        call_user_func([$migration, 'up']);

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

    /** Persist one canonical seed row inside its owner admission. */
    private function seed(
        OwnershipRunner $runner,
        OwnershipWriter $writer,
        string $ownerKey,
        string $group,
        string $locale,
        string $name,
    ): TenantSelfEntry {
        return $runner->run(TenantTranslationOwner::findOrFail($ownerKey), OwnershipActor::firstOrFail(),
            static fn (): TenantSelfEntry => $writer->create(TenantSelfEntry::class,
                static function (OwnershipAssignment $assignment) use ($group, $locale, $name): TenantSelfEntry {
                    $entry = new TenantSelfEntry(['entry_key' => $group, 'locale' => $locale, 'name' => $name]);
                    $entry->forceFill($assignment->attributes());
                    $entry->saveOrFail();

                    return $entry;
                }));
    }

    /** Publish valid probes and corrupt only the final stored scalar envelope. */
    private function publishProbes(
        string $queue,
        string $recordA,
        string $recordB,
        OwnershipRunner $runner,
        OwnershipContext $context,
    ): void {
        try {
            $uncaptured = new TenantTranslationProbeJob($recordA, 'en', 'missing-context', OwnershipJobEnvelope::capture($context));
            Bus::dispatch($uncaptured->onConnection('database')->onQueue($queue));
            throw new RuntimeException('Missing ownership context unexpectedly dispatched.');
        } catch (OwnershipViolation) {
        }

        $this->dispatchProbe($runner, TenantTranslationScenario::A, $recordA, 'en', 'a-en', $queue);
        $this->dispatchProbe($runner, TenantTranslationScenario::B, $recordB, 'bg', 'b-bg', $queue);
        $this->dispatchProbe($runner, TenantTranslationScenario::A, $recordA, 'en', 'failure-a-en', $queue, true);
        $this->dispatchProbe($runner, TenantTranslationScenario::A, $recordA, 'en', 'corrupt-before-read', $queue);

        $connection = DB::connection();
        $job = $connection->table('jobs')->where('queue', $queue)->where('payload', 'like', '%corrupt-before-read%')->first()
            ?? $connection->table('jobs')->where('queue', $queue)->orderByDesc('id')->first();
        if (! is_object($job) || ! is_string($job->payload ?? null)) {
            throw new RuntimeException('The final serialized probe was not stored.');
        }
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('The final serialized probe payload is invalid.');
        }
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_array($data['nvl_ownership'] ?? null)
            || ! is_array($data['nvl_ownership']['owner'] ?? null)) {
            throw new RuntimeException('The final serialized probe has no ownership envelope.');
        }
        $data['nvl_ownership']['owner']['key'] = TenantTranslationScenario::B;
        $payload['data'] = $data;
        $connection->table('jobs')->where('id', $job->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Dispatch one scalar probe from an admitted producer owner. */
    private function dispatchProbe(
        OwnershipRunner $runner,
        string $ownerKey,
        string $record,
        string $locale,
        string $result,
        string $queue,
        bool $throw = false,
    ): void {
        $runner->run(TenantTranslationOwner::findOrFail($ownerKey), OwnershipActor::firstOrFail(),
            static function () use ($record, $locale, $result, $queue, $throw): void {
                $job = new TenantTranslationProbeJob(
                    $record, $locale, $result,
                    OwnershipJobEnvelope::capture(app(OwnershipContext::class)),
                    $throw,
                );
                Bus::dispatch($job->onConnection('database')->onQueue($queue));
            });
    }

    /** Print one canonical logical version without mutating fixture state. */
    private function inspect(
        string $ownerKey,
        string $group,
        OwnershipRunner $runner,
        TranslationResourceVersioner $versioner,
    ): int {
        $result = $runner->run(TenantTranslationOwner::findOrFail($ownerKey), OwnershipActor::firstOrFail(),
            static function () use ($group, $versioner): array {
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
