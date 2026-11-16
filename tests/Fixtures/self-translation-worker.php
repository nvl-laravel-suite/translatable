<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\SelfTranslationStore;
use Nvl\Translatable\Tests\Support\TestSelfTranslatableModel;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_string($source['database'] ?? null) || preg_match('/^nvl_translatable_test_[a-f0-9]{8}_ci$/D', $source['database']) !== 1
    || ! is_array($options) || ! is_string($options['directory'] ?? null) || ! is_dir($options['directory'])
    || preg_match('/^nvl-translatable-self-race-[a-f0-9]{16}$/D', basename($options['directory'])) !== 1
    || ! is_int($options['row'] ?? null) || ! is_string($options['locale'] ?? null) || ! is_string($options['name'] ?? null)) {
    throw new RuntimeException('Self translation race workers require the dedicated PostgreSQL package database and barriers.');
}

$await = static function (string $file) use ($options): void {
    $deadline = microtime(true) + 15;
    while (! is_file($options['directory'].'/'.$file)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("The self translation [{$file}] barrier timed out.");
        }
        usleep(10000);
        clearstatcache();
    }
};

$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [LocaleServiceProvider::class, TranslatableServiceProvider::class],
]]);
$app->make(Kernel::class)->bootstrap();
unset($source['name']);
$app->make('config')->set([
    'database.default' => 'self_race',
    'database.connections.self_race' => $source,
    'nvl-translatable.locales' => ['en', 'bg', 'en-GB'],
    'nvl-translatable.default_locale' => 'en',
    'nvl-translatable.fallback_locales' => ['en'],
]);

$connection = DB::connection();
$row = TestSelfTranslatableModel::query()->findOrFail($options['row']);
$store = $app->make(SelfTranslationStore::class);
$ready = $connection->selectOne('select current_database() as database, pg_backend_pid() as connection_id');
file_put_contents($options['directory'].'/ready.pending', json_encode(['database' => $ready->database, 'connection_id' => (int) $ready->connection_id], JSON_THROW_ON_ERROR));
rename($options['directory'].'/ready.pending', $options['directory'].'/ready');
$await('release');

try {
    $written = $connection->transaction(static fn () => $store->upsert($row, $row->translationDefinition(), $options['locale'], ['name' => $options['name']]));
    $outcome = ['status' => 'written', 'id' => (int) $written->getKey()];
} catch (Throwable $exception) {
    $outcome = ['status' => 'failed', 'message' => $exception->getMessage()];
}

fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR));
