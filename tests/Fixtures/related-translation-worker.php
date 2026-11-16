<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Tests\Support\SoftDeletingRelatedOwner;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_string($source['database'] ?? null) || preg_match('/^nvl_translatable_test_[a-f0-9]{8}_ci$/D', $source['database']) !== 1
    || ! is_array($options) || ! is_string($options['directory'] ?? null) || ! is_dir($options['directory'])
    || preg_match('/^nvl-translatable-race-[a-f0-9]{16}$/D', basename($options['directory'])) !== 1
    || ! in_array($options['index'] ?? null, [0, 1], true) || ! is_int($options['owner'] ?? null)
    || ! is_bool($options['restore'] ?? null) || ! is_string($options['name'] ?? null)) {
    throw new RuntimeException('Related translation race workers require the dedicated PostgreSQL package database and barriers.');
}

$await = static function (string $file) use ($options): void {
    $deadline = microtime(true) + 15;
    while (! is_file($options['directory'].'/'.$file)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("The related translation [{$file}] barrier timed out.");
        }
        usleep(10000);
        clearstatcache();
    }
};
$signal = static function (string $file, array $payload) use ($options): void {
    file_put_contents($options['directory'].'/'.$file.'.pending', json_encode($payload, JSON_THROW_ON_ERROR));
    rename($options['directory'].'/'.$file.'.pending', $options['directory'].'/'.$file);
};

$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [LocaleServiceProvider::class, TranslatableServiceProvider::class],
]]);
$app->make(Kernel::class)->bootstrap();
unset($source['name']);
$app->make('config')->set([
    'database.default' => 'related_race',
    'database.connections.related_race' => $source,
    'nvl-translatable.locales' => ['en', 'bg'],
    'nvl-translatable.default_locale' => 'en',
    'nvl-translatable.fallback_locales' => ['en'],
    'nvl-translatable.test_restore_deleted' => $options['restore'],
]);

$connection = DB::connection();
$identity = static function () use ($connection): array {
    $row = $connection->selectOne('select current_database() as database, pg_backend_pid() as connection_id');

    return ['database' => $row->database, 'connection_id' => (int) $row->connection_id];
};
$owner = SoftDeletingRelatedOwner::query()->findOrFail($options['owner']);
$store = $app->make(RelatedTranslationStore::class);
$signal('ready-'.$options['index'], $identity());
$await('release-'.$options['index']);

$outcome = $connection->transaction(static function () use ($store, $owner, $options, $await, $signal): array {
    try {
        $row = $store->upsert($owner, $owner->translationDefinition(), 'bg', ['name' => $options['name']]);
        $outcome = ['status' => 'written', 'id' => (int) $row->getKey()];
    } catch (TranslatableException $exception) {
        $outcome = ['status' => 'rejected', 'message' => $exception->getMessage()];
    }
    if ($options['index'] === 0) {
        $signal('held-0', $outcome);
        $await('commit-0');
    }

    return $outcome;
});

fwrite(STDOUT, json_encode([...$outcome, ...$identity()], JSON_THROW_ON_ERROR));
