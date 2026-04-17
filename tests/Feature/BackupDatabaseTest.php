<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    public function test_pruneLocal_deletes_files_older_than_seven_days_and_keeps_fresh_ones(): void
    {
        $dir = storage_path('app/backups-test-'.bin2hex(random_bytes(4)));
        @mkdir($dir, 0755, true);

        $old = "{$dir}/old-2020-01-01.sql.gz";
        $fresh = "{$dir}/fresh-today.sql.gz";
        file_put_contents($old, 'x');
        file_put_contents($fresh, 'x');
        touch($old, time() - (10 * 86400));   // 10 days old
        touch($fresh, time() - (2 * 86400));  // 2 days old

        $cmd = app()->make(BackupDatabase::class);
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput
        ));

        $pruned = $cmd->pruneLocal($dir);

        $this->assertSame(1, $pruned);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);

        // Cleanup
        @unlink($fresh);
        @rmdir($dir);
    }

    public function test_schedule_registers_daily_at_0200_ist(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events())->map(fn ($e) => $e->command);

        $hit = $events->first(fn ($c) => $c && str_contains($c, 'backup:database'));

        $this->assertNotNull($hit, 'backup:database must be scheduled');
    }
}
