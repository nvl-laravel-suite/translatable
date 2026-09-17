<?php

declare(strict_types=1);

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
        $results = $pdo->query('select result_key, tenant_id, value from tenant_probe_results order by result_key')
            ->fetchAll(PDO::FETCH_ASSOC);

        expect($results)->toContain(
            ['result_key' => 'a-en', 'tenant_id' => TenantTranslationScenario::A, 'value' => 'A fallback'],
            ['result_key' => 'b-bg', 'tenant_id' => TenantTranslationScenario::B, 'value' => 'B requested'],
            ['result_key' => 'failure-a-en', 'tenant_id' => TenantTranslationScenario::A, 'value' => 'A fallback'],
            ['result_key' => 'worker-scope', 'tenant_id' => null, 'value' => 'en'],
        )->and(array_column($results, 'result_key'))->not->toContain('corrupt-before-read')
            ->and((int) $pdo->query('select count(*) from failed_jobs')->fetchColumn())->toBe(2)
            ->and((int) $pdo->query('select count(*) from jobs')->fetchColumn())->toBe(0);
    } finally {
        $files->remove($fixture);
    }
});
