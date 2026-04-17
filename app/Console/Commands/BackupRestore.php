<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
                            {file : Path to .sql.gz dump (absolute, or filename inside storage/app/backups/)}
                            {--force : Skip the destructive-action confirmation}';

    protected $description = 'Restore the MySQL DB from a gzipped mysqldump file. DESTRUCTIVE: drops and recreates existing tables.';

    public function handle(): int
    {
        $input = (string) $this->argument('file');
        $resolved = $this->resolvePath($input);

        if (! is_file($resolved)) {
            $this->error("File not found: {$resolved}");
            return self::FAILURE;
        }
        if (! str_ends_with($resolved, '.sql.gz')) {
            $this->error('Expected a .sql.gz file.');
            return self::FAILURE;
        }

        $host = (string) config('database.connections.mysql.host');
        $db   = (string) config('database.connections.mysql.database');
        $user = (string) config('database.connections.mysql.username');
        $pass = (string) config('database.connections.mysql.password');

        $this->warn("About to RESTORE '{$db}' on {$host} from {$resolved}.");
        $this->warn('This replaces all current tables. There is no undo.');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('Aborted.');
            return self::FAILURE;
        }

        $cmd = sprintf(
            'gunzip -c %s | mysql -h%s -u%s -p%s %s',
            escapeshellarg($resolved),
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($db),
        );

        exec($cmd.' 2>&1', $out, $exit);
        if ($exit !== 0) {
            $this->error('Restore failed (exit '.$exit.'):');
            foreach ($out as $line) {
                $this->line('  '.$line);
            }
            return self::FAILURE;
        }

        $this->info("Restored {$db} from {$resolved}.");
        return self::SUCCESS;
    }

    public function resolvePath(string $input): string
    {
        if (str_starts_with($input, '/')) {
            return $input;
        }
        return storage_path('app/backups/'.ltrim($input, '/'));
    }
}
