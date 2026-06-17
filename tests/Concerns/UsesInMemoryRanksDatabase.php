<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Points the `ranks` connection at a fresh in-memory SQLite database and migrates
 * the ranks schema onto it, per test.
 *
 * Use this in any rank test that WRITES cutoff/institute/etc. data. It keeps those
 * tests hermetic and off the real MySQL dev fixture, so a long run can't exhaust
 * MySQL connections and cascade-fail unrelated tests. Read-only tests that assert
 * against the real dev fixture (e.g. RankLookupTest) intentionally do NOT use it.
 *
 * Call setUpInMemoryRanksDatabase() from the test's setUp() (after parent::setUp()).
 * No `connectionsToTransact` needed — each test gets a fresh, empty, migrated DB.
 */
trait UsesInMemoryRanksDatabase
{
    protected function setUpInMemoryRanksDatabase(): void
    {
        config()->set('database.connections.ranks', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // Drop any previously resolved (real-MySQL or prior in-memory) connection so
        // the next access opens a brand-new, empty in-memory database.
        DB::purge('ranks');

        Artisan::call('migrate', [
            '--database' => 'ranks',
            '--path' => 'database/migrations/ranks',
            '--force' => true,
        ]);
    }
}
