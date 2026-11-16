<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Nvl\Translatable\Tests\Support\RelatedIdentitySchema;
use RuntimeException;

/** Runs related translation writes on committed PostgreSQL storage, with no wrapping test transaction. */
abstract class CommittedRelatedTranslationTestCase extends TestCase
{
    /** Return the dedicated committed package database, or skip outside the PostgreSQL gate. */
    protected function committedSource(bool $uniqueIndex): Connection
    {
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires the dedicated committed PostgreSQL package database.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql'
            || preg_match('/^nvl_translatable_test_[a-f0-9]{8}_ci$/D', $connection->getDatabaseName()) !== 1
            || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Committed related translation tests require their dedicated PostgreSQL package database.');
        }
        RelatedIdentitySchema::create($connection->getSchemaBuilder(), $uniqueIndex);
        $this->beforeApplicationDestroyed(static fn () => RelatedIdentitySchema::drop($connection->getSchemaBuilder()));

        return $connection;
    }
}
