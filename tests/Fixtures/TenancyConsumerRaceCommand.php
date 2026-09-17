<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Tests\Support\TenantSelfEntry;
use RuntimeException;

/** Runs one independently booted writer after a shared Redis barrier releases. */
final class TenancyConsumerRaceCommand extends Command
{
    protected $signature = 'tenancy-fixture:race {tenant} {group} {locale} {value} {barrier}';

    protected $description = 'Compete to create one tenant translation locale.';

    /** Wait with a bounded deadline, then perform the canonical transactional write. */
    public function handle(TenantRunner $runner, TranslationWriter $writer): int
    {
        $tenant = $this->stringArgument('tenant');
        $group = $this->stringArgument('group');
        $locale = $this->stringArgument('locale');
        $value = $this->stringArgument('value');
        $barrier = $this->stringArgument('barrier');
        Redis::setex($barrier.':ready:'.hash('sha256', $value), 20, 'ready');
        $deadline = microtime(true) + 10;
        while (Redis::get($barrier) !== 'go') {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The Redis race barrier timed out.');
            }
            usleep(20_000);
        }

        $runner->run(new TenantId($tenant), static function () use ($writer, $group, $locale, $value): void {
            $owner = TenantSelfEntry::query()->locale('en')->where('entry_key', $group)->firstOrFail();
            $owner->getConnection()->transaction(
                static fn () => $writer->upsert($owner, $locale, ['name' => $value]),
            );
        });
        $driver = config('database.default');
        if (! is_string($driver)) {
            throw new RuntimeException('The fixture database driver is invalid.');
        }
        $this->line('driver='.$driver.' race=ok value='.$value);

        return self::SUCCESS;
    }

    /** Read one required scalar command argument. */
    private function stringArgument(string $key): string
    {
        $value = $this->argument($key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The [{$key}] race argument must be a non-empty string.");
        }

        return $value;
    }
}
