<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('restores tenant translation context in a real database queue worker', function (): void {
    $root = dirname(__DIR__, 6);
    $source = $root.'/packages/nvl/translatable/tests/Fixtures/tenancy-consumer';
    $fixture = sys_get_temp_dir().'/nvl-translatable-worker-'.bin2hex(random_bytes(8));
    $database = $fixture.'/database.sqlite';
    $queue = 'translation-tenant-proof-'.bin2hex(random_bytes(6));
    $files = new Filesystem;

    try {
        $files->mirror($source, $fixture);
        $files->mkdir([
            $fixture.'/app',
            $fixture.'/bootstrap/cache',
            $fixture.'/storage/framework/cache/data',
            $fixture.'/storage/logs',
        ]);
        touch($database);

        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'NVL_TEST_SUITE_ROOT' => $root,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'database',
        ];
        $setup = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'tenancy-fixture:setup',
            '--queue='.$queue,
            '--no-interaction',
        ], $fixture, $environment, timeout: 60);
        $setup->run();

        expect($setup->isSuccessful())->toBeTrue($setup->getOutput().$setup->getErrorOutput())
            ->and($setup->getOutput())->toContain('driver=sqlite', 'setup=ok', 'queue='.$queue);

        $setupPdo = new PDO('sqlite:'.$database);
        $setupPdo->exec('update jobs set available_at = 0');
        unset($setupPdo);

        $worker = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'queue:work',
            'database',
            '--queue='.$queue,
            '--stop-when-empty',
            '--tries=1',
            '--timeout=30',
            '--sleep=0',
            '--no-interaction',
        ], $fixture, $environment, timeout: 60);
        $worker->run();

        expect($worker->isSuccessful())->toBeTrue($worker->getOutput().$worker->getErrorOutput())
            ->and($worker->getOutput())->toContain('TenantTranslationProbeJob');

        $pdo = new PDO('sqlite:'.$database);
        $drainAttempts = 0;
        while ($drainAttempts < 3 && (int) $pdo->query("select count(*) from jobs where queue = '{$queue}'")->fetchColumn() > 0) {
            $drainAttempts++;
            $pdo->exec('update jobs set available_at = 0');
            unset($pdo);
            $drainWorker = new Process([
                PHP_BINARY,
                $fixture.'/artisan.php',
                'queue:work',
                'database',
                '--queue='.$queue,
                '--stop-when-empty',
                '--tries=1',
                '--timeout=30',
                '--sleep=0',
                '--no-interaction',
            ], $fixture, $environment, timeout: 60);
            $drainWorker->run();
            $pdo = new PDO('sqlite:'.$database);
            if (! $drainWorker->isSuccessful()) {
                break;
            }
        }

        $results = $pdo->query('select result_key, tenant_id, value from tenant_probe_results order by result_key')
            ->fetchAll(PDO::FETCH_ASSOC);
        $failed = $pdo->query('select exception from failed_jobs order by id')
            ->fetchAll(PDO::FETCH_COLUMN);
        $translationFailures = array_values(array_filter(
            $failed,
            static fn (string $exception): bool => str_contains($exception, 'translation probe'),
        ));
        $corruptEnvelopeFailures = array_values(array_filter(
            $failed,
            static fn (string $exception): bool => str_contains($exception, 'A carried envelope differs from the queued tenant boundary.'),
        ));

        $diagnostics = json_encode([
            'results' => $results,
            'failed' => array_map(static fn (string $exception): string => substr($exception, 0, 500), $failed),
            'remaining_jobs' => $pdo->query('select id, queue, attempts, available_at from jobs')->fetchAll(PDO::FETCH_ASSOC),
            'worker_output' => $worker->getOutput(),
            'worker_error' => $worker->getErrorOutput(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $expectedResults = [
            ['result_key' => 'a-en', 'tenant_id' => TenantTranslationScenario::A, 'value' => 'A fallback'],
            ['result_key' => 'b-bg', 'tenant_id' => TenantTranslationScenario::B, 'value' => 'B requested'],
            ['result_key' => 'failure-a-en', 'tenant_id' => TenantTranslationScenario::A, 'value' => 'A fallback'],
            ['result_key' => 'worker-scope', 'tenant_id' => null, 'value' => 'en'],
        ];

        foreach ($expectedResults as $expectedResult) {
            if (! in_array($expectedResult, $results, true)) {
                test()->fail('Missing expected probe result '.json_encode($expectedResult).":\n".$diagnostics);
            }
        }

        expect($results)->toContain(
            ['result_key' => 'a-en', 'tenant_id' => TenantTranslationScenario::A, 'value' => 'A fallback'],
            ['result_key' => 'b-bg', 'tenant_id' => TenantTranslationScenario::B, 'value' => 'B requested'],
            ['result_key' => 'failure-a-en', 'tenant_id' => TenantTranslationScenario::A, 'value' => 'A fallback'],
            ['result_key' => 'worker-scope', 'tenant_id' => null, 'value' => 'en'],
        )->and(array_column($results, 'result_key'))->not->toContain('corrupt-before-read')
            ->and($failed)->toHaveCount(2, $diagnostics)
            ->and($translationFailures)->toHaveCount(1, $diagnostics)
            ->and($translationFailures[0])->toContain(RuntimeException::class, 'translation probe')
            ->and($corruptEnvelopeFailures)->toHaveCount(1, $diagnostics)
            ->and($corruptEnvelopeFailures[0])->toContain(
                TenantBoundaryViolation::class,
                'A carried envelope differs from the queued tenant boundary.',
            )
            ->and($corruptEnvelopeFailures[0])->not->toContain(ModelNotFoundException::class)
            ->and((int) $pdo->query('select count(*) from jobs')->fetchColumn())->toBe(0, $diagnostics);
    } finally {
        $files->remove($fixture);
    }
});
