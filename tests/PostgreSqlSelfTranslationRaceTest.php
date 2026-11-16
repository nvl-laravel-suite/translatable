<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Nvl\Translatable\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

it('serializes writers entering one group through different locale rows', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires the dedicated committed PostgreSQL package database.');
    }
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql'
        || preg_match('/^nvl_translatable_test_[a-f0-9]{8}_ci$/D', $connection->getDatabaseName()) !== 1
        || $connection->transactionLevel() !== 0) {
        throw new RuntimeException('Self translation races require their dedicated PostgreSQL package database.');
    }
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists('test_self_translatable_models');
    $schema->create('test_self_translatable_models', static function (Blueprint $table): void {
        $table->id();
        $table->string('entry_key');
        $table->string('locale', 35);
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->nullable();
        $table->unique(['entry_key', 'locale'], 'self_translation_unique');
    });
    $this->beforeApplicationDestroyed(static fn () => $schema->dropIfExists('test_self_translatable_models'));
    $english = TestSelfTranslatableModel::query()->create(['entry_key' => 'race', 'locale' => 'en', 'name' => 'English']);
    $bulgarian = TestSelfTranslatableModel::query()->create(['entry_key' => 'race', 'locale' => 'bg', 'name' => 'Bulgarian']);
    expect($english->getKey())->toBeLessThan($bulgarian->getKey());

    $source = $connection->getConfig();
    unset($source['name']);
    config()->set('database.connections.self_race_holder', $source);
    $holder = DB::connection('self_race_holder');
    $holderPid = (int) $holder->scalar('select pg_backend_pid()');
    $directory = sys_get_temp_dir().'/nvl-translatable-self-race-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create the self translation barrier directory.');
    }
    $worker = new Process([
        PHP_BINARY,
        __DIR__.'/Fixtures/self-translation-worker.php',
        json_encode($source, JSON_THROW_ON_ERROR),
        json_encode(['directory' => $directory, 'row' => $bulgarian->getKey(), 'locale' => 'bg', 'name' => 'Worker'], JSON_THROW_ON_ERROR),
    ], env: ['XDEBUG_MODE' => 'off'], timeout: 40);

    try {
        $holder->beginTransaction();
        $holder->select('select id from test_self_translatable_models where id = ? for update', [$english->getKey()]);
        $worker->start();
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/ready')) {
            $worker->checkTimeout();
            if (! $worker->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('The self translation worker never became ready: '.$worker->getErrorOutput());
            }
            usleep(10000);
            clearstatcache();
        }
        $ready = json_decode((string) file_get_contents($directory.'/ready'), true, flags: JSON_THROW_ON_ERROR);
        expect($ready['database'])->toBe($connection->getDatabaseName());
        file_put_contents($directory.'/release', 'release');

        $deadline = microtime(true) + 15;
        while (! in_array($holderPid, array_map(intval(...), explode(',', trim((string) $connection->scalar('select pg_blocking_pids(?)::text', [$ready['connection_id']], false), '{}'))), true)) {
            if (! $worker->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('The worker never waited on the first group row held by the other writer: '.$worker->getErrorOutput());
            }
            usleep(10000);
        }

        $holder->statement("set local lock_timeout = '10s'");
        $holder->select('select id from test_self_translatable_models where id = ? for update', [$bulgarian->getKey()]);
        $holder->table('test_self_translatable_models')->where('id', $english->getKey())->update(['name' => 'Holder']);
        $holder->commit();

        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        expect(json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'written', 'id' => $bulgarian->getKey()])
            ->and(TestSelfTranslatableModel::query()->where('entry_key', 'race')->orderBy('id')->pluck('name')->all())->toBe(['Holder', 'Worker']);
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        $worker->stop();
        $holder->disconnect();
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
