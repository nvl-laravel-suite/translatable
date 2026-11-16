<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Translatable\Tests\CommittedRelatedTranslationTestCase;
use Nvl\Translatable\Tests\Support\SoftDeletingRelatedOwner;
use Nvl\Translatable\Tests\Support\SoftDeletingRelatedTranslation;
use Symfony\Component\Process\Process;

uses(CommittedRelatedTranslationTestCase::class);

it('serializes restore-or-reject across independent PostgreSQL processes', function (bool $deleted, bool $restore, bool $uniqueIndex): void {
    $connection = $this->committedSource($uniqueIndex);
    config()->set('nvl-translatable.test_restore_deleted', $restore);
    $owner = SoftDeletingRelatedOwner::query()->create(['slug' => 'race']);
    $initial = $deleted ? (int) DB::table('related_identity_translations')->insertGetId([
        'related_identity_owner_id' => $owner->id, 'locale' => 'bg', 'name' => 'Old', 'description' => 'Retained', 'deleted_at' => now(),
    ]) : null;
    $parent = $connection->selectOne('select current_database() as database, pg_backend_pid() as connection_id');
    $source = $connection->getConfig();
    unset($source['name']);

    $directory = sys_get_temp_dir().'/nvl-translatable-race-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create the related translation barrier directory.');
    }
    $workers = [];
    $await = static function (string $file) use (&$workers, $directory): array {
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/'.$file)) {
            foreach ($workers as $worker) {
                $worker->checkTimeout();
                if (! $worker->isRunning()) {
                    throw new RuntimeException("A related translation worker exited before [{$file}]: ".$worker->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("The related translation [{$file}] barrier timed out.");
            }
            usleep(10000);
            clearstatcache();
        }

        return json_decode((string) file_get_contents($directory.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
    };

    try {
        foreach ([0, 1] as $index) {
            $worker = new Process([
                PHP_BINARY,
                __DIR__.'/Fixtures/related-translation-worker.php',
                json_encode($source, JSON_THROW_ON_ERROR),
                json_encode(['directory' => $directory, 'index' => $index, 'owner' => $owner->id, 'restore' => $restore, 'name' => 'Worker '.$index], JSON_THROW_ON_ERROR),
            ], env: ['XDEBUG_MODE' => 'off'], timeout: 40);
            $worker->start();
            $workers[] = $worker;
        }
        $ready = [$await('ready-0'), $await('ready-1')];
        expect(array_column($ready, 'database'))->toBe([$parent->database, $parent->database])
            ->and(array_unique([(int) $parent->connection_id, ...array_column($ready, 'connection_id')]))->toHaveCount(3);
        [$holder, $waiter] = array_column($ready, 'connection_id');

        file_put_contents($directory.'/release-0', 'release');
        $await('held-0');
        file_put_contents($directory.'/release-1', 'release');
        $deadline = microtime(true) + 15;
        while (! in_array($holder, array_map(intval(...), explode(',', trim((string) $connection->scalar('select pg_blocking_pids(?)::text', [$waiter], false), '{}'))), true)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The second worker never waited on the owner lock held by the first.');
            }
            usleep(10000);
        }
        file_put_contents($directory.'/commit-0', 'commit');

        $outcomes = [];
        foreach ($workers as $index => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[$index] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($outcomes[$index]['connection_id'])->toBe($ready[$index]['connection_id'])
                ->and($outcomes[$index]['database'])->toBe($parent->database);
        }
        $rows = SoftDeletingRelatedTranslation::withTrashed()->get();

        expect($rows)->toHaveCount(1);
        if ($deleted && ! $restore) {
            expect(array_column($outcomes, 'status'))->toBe(['rejected', 'rejected'])
                ->and($outcomes[0]['message'])->toContain('soft-deleted')
                ->and($outcomes[1]['message'])->toContain('soft-deleted')
                ->and($rows->sole()->getKey())->toBe($initial)
                ->and($rows->sole()->trashed())->toBeTrue()
                ->and($rows->sole()->getAttribute('name'))->toBe('Old');
        } else {
            expect(array_column($outcomes, 'status'))->toBe(['written', 'written'])
                ->and($outcomes[1]['id'])->toBe($outcomes[0]['id'])
                ->and($rows->sole()->getKey())->toBe($outcomes[0]['id'])
                ->and($rows->sole()->trashed())->toBeFalse()
                ->and($rows->sole()->getAttribute('name'))->toBe('Worker 1');
            if ($deleted) {
                expect($outcomes[0]['id'])->toBe($initial)
                    ->and($rows->sole()->getAttribute('description'))->toBe('Retained');
            }
        }
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with(['missing row' => false, 'deleted row' => true])
    ->with(['restore' => true, 'reject' => false])
    ->with(['unique index' => true, 'no unique index' => false]);
