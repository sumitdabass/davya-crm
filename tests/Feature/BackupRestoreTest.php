<?php

namespace Tests\Feature;

use App\Console\Commands\BackupRestore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    public function test_resolvePath_treats_absolute_paths_as_is(): void
    {
        $cmd = app()->make(BackupRestore::class);
        $this->assertSame('/tmp/foo.sql.gz', $cmd->resolvePath('/tmp/foo.sql.gz'));
    }

    public function test_resolvePath_resolves_bare_filename_relative_to_storage_app_backups(): void
    {
        $cmd = app()->make(BackupRestore::class);
        $expected = storage_path('app/backups/davya-2026.sql.gz');
        $this->assertSame($expected, $cmd->resolvePath('davya-2026.sql.gz'));
    }

    public function test_command_fails_fast_when_file_does_not_exist(): void
    {
        $exit = Artisan::call('backup:restore', [
            'file' => '/tmp/definitely-not-there-'.uniqid().'.sql.gz',
            '--force' => true,
        ]);
        $this->assertSame(1, $exit);
    }

    public function test_command_fails_fast_on_non_sql_gz_extension(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'backuprestore-').'.txt';
        file_put_contents($file, 'x');

        $exit = Artisan::call('backup:restore', [
            'file' => $file,
            '--force' => true,
        ]);

        $this->assertSame(1, $exit);
        @unlink($file);
    }
}
