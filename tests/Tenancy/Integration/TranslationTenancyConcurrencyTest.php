<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__, 2).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Translatable\\Tests\\TenancyTestCase');
}

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Nvl\Translatable\Tests\Support\TenantTranslationScenario;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('serializes competing tenant locale creation in separate PostgreSQL processes', function (): void {
    if (getenv('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('The required concurrency gate needs PostgreSQL; SQLite is not concurrency evidence.');
    }
    $database = DB::connection('pgsql');
    if (preg_match('/^nvl_translatable_test_[a-f0-9]{8}_ci$/D', $database->getDatabaseName()) !== 1) {
        throw new RuntimeException('The concurrency gate requires a dedicated PostgreSQL package test database.');
    }

    $redisSocket = getenv('REDIS_SOCKET');
    $redisHost = getenv('REDIS_HOST');
    if ((! is_string($redisSocket) || $redisSocket === '') && (! is_string($redisHost) || $redisHost === '')) {
        $this->markTestSkipped('The required concurrency gate needs the dedicated Redis barrier.');
    }

    $vendor = dirname(InstalledVersions::getInstallPath('laravel/framework') ?? throw new RuntimeException('Laravel test dependencies are not installed.'), 2);
    $source = dirname(__DIR__, 2).'/Fixtures/tenancy-consumer';
    $fixture = sys_get_temp_dir().'/nvl-translatable-race-'.bin2hex(random_bytes(8));
    $schema = 'nvl_translatable_'.bin2hex(random_bytes(8));
    $barrier = 'nvl:translatable:race:'.bin2hex(random_bytes(12));
    $files = new Filesystem;
    $redis = new Redis;
    $actors = [];

    try {
        $database->statement('create schema "'.$schema.'"');
        $files->mirror($source, $fixture);
        $files->mkdir([
            $fixture.'/app',
            $fixture.'/bootstrap/cache',
            $fixture.'/storage/framework/cache/data',
            $fixture.'/storage/framework/views',
            $fixture.'/storage/logs',
        ]);

        $environment = [
            'APP_ENV' => 'testing',
            'APP_BASE_PATH' => $fixture,
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'NVL_TEST_VENDOR_DIR' => $vendor,
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) getenv('DB_HOST'),
            'DB_PORT' => (string) getenv('DB_PORT'),
            'DB_DATABASE' => (string) getenv('DB_DATABASE'),
            'DB_USERNAME' => (string) getenv('DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('DB_PASSWORD'),
            'DB_SCHEMA' => $schema,
            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'database',
            'REDIS_SOCKET' => is_string($redisSocket) ? $redisSocket : '',
            'REDIS_HOST' => is_string($redisHost) ? $redisHost : '',
            'REDIS_PORT' => (string) (getenv('REDIS_PORT') ?: '6379'),
            'REDIS_PREFIX' => '',
        ];
        $setup = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'tenancy-fixture:setup',
            '--no-interaction',
        ], $fixture, $environment, timeout: 60);
        $setup->mustRun();

        if (is_string($redisSocket) && $redisSocket !== '') {
            $redis->connect($redisSocket);
        } else {
            $redis->connect((string) $redisHost, (int) ($environment['REDIS_PORT']));
        }

        foreach (['Race A', 'Race B'] as $value) {
            $actors[$value] = new Process([
                PHP_BINARY,
                $fixture.'/artisan.php',
                'tenancy-fixture:race',
                TenantTranslationScenario::A,
                'same',
                'bg',
                $value,
                $barrier,
                '--no-interaction',
            ], $fixture, $environment, timeout: 20);
            $actors[$value]->start();
        }

        $deadline = microtime(true) + 10;
        do {
            $ready = (int) $redis->exists($barrier.':ready:'.hash('sha256', 'Race A'))
                + (int) $redis->exists($barrier.':ready:'.hash('sha256', 'Race B'));
            if ($ready === 2) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $actorDiagnostics = implode("\n", array_map(
            static fn (Process $actor, string $value): string => $value.': '.$actor->getOutput().$actor->getErrorOutput(),
            $actors,
            array_keys($actors),
        ));
        expect($ready)->toBe(2, "Both PostgreSQL actors must reach the Redis barrier before release.\n".$actorDiagnostics);
        $redis->set($barrier, 'go', ['ex' => 20]);

        $committed = [];
        $stale = [];
        foreach ($actors as $value => $actor) {
            $actor->wait();
            expect($actor->isSuccessful())->toBeTrue($value.': '.$actor->getOutput().$actor->getErrorOutput())
                ->and($actor->getOutput())->toContain('driver=pgsql');
            if (str_contains($actor->getOutput(), 'race=committed')) {
                $committed[] = $value;
            }
            if (str_contains($actor->getOutput(), 'race=stale')) {
                $stale[] = $value;
            }
        }
        expect($committed)->toHaveCount(1)->and($stale)->toHaveCount(1);

        $pdo = new PDO(
            'pgsql:host='.$environment['DB_HOST'].';port='.$environment['DB_PORT'].';dbname='.$environment['DB_DATABASE'],
            $environment['DB_USERNAME'],
            $environment['DB_PASSWORD'],
        );
        $pdo->exec('set search_path to "'.$schema.'"');
        $rows = $pdo->query("select tenant_id, locale, name from tenant_test_entries where entry_key = 'same' order by tenant_id, locale")
            ->fetchAll(PDO::FETCH_ASSOC);
        $tenantABulgarian = array_values(array_filter($rows, static fn (array $row): bool => $row['tenant_id'] === TenantTranslationScenario::A && $row['locale'] === 'bg'));
        $tenantBBulgarian = array_values(array_filter($rows, static fn (array $row): bool => $row['tenant_id'] === TenantTranslationScenario::B && $row['locale'] === 'bg'));

        expect($tenantABulgarian)->toHaveCount(1)
            ->and($tenantABulgarian[0]['name'])->toBe($committed[0])
            ->and($tenantBBulgarian)->toBe([['tenant_id' => TenantTranslationScenario::B, 'locale' => 'bg', 'name' => 'B requested']]);

        $inspect = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'tenancy-fixture:setup',
            '--inspect='.TenantTranslationScenario::A,
            '--group=same',
            '--no-interaction',
        ], $fixture, $environment, timeout: 30);
        $inspect->mustRun();
        $inspection = json_decode(trim($inspect->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($inspection['version'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($inspection['locale_count'])->toBe(2);
    } finally {
        foreach ($actors as $actor) {
            $actor->stop();
        }
        if ($redis->isConnected()) {
            $redis->del([
                $barrier,
                $barrier.':ready:'.hash('sha256', 'Race A'),
                $barrier.':ready:'.hash('sha256', 'Race B'),
            ]);
        }
        $database->statement('drop schema if exists "'.$schema.'" cascade');
        $files->remove($fixture);
    }
});
