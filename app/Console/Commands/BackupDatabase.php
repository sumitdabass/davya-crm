<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--skip-drive : Skip Drive upload even if credentials are present}';

    protected $description = 'mysqldump + optional Drive upload with retention (7d local / 30d Drive)';

    public function handle(): int
    {
        $filename = config('database.connections.mysql.database').'-'.now('Asia/Kolkata')->format('Y-m-d-His').'.sql.gz';
        $localDir = storage_path('app/backups');
        @mkdir($localDir, 0755, true);
        $local = "{$localDir}/{$filename}";

        $host = (string) config('database.connections.mysql.host');
        $db   = (string) config('database.connections.mysql.database');
        $user = (string) config('database.connections.mysql.username');
        $pass = (string) config('database.connections.mysql.password');

        $cmd = sprintf(
            'mysqldump --single-transaction --quick --no-tablespaces --add-drop-table -h%s -u%s -p%s %s | gzip > %s',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($db),
            escapeshellarg($local),
        );

        exec($cmd, $out, $exit);
        if ($exit !== 0) {
            $this->error('mysqldump failed (exit '.$exit.')');
            return self::FAILURE;
        }

        $size = is_file($local) ? filesize($local) : 0;
        $this->info("Local backup {$filename} ({$size} bytes).");

        $this->pruneLocal($localDir);

        if (! $this->option('skip-drive') && $this->driveConfigured()) {
            try {
                Storage::disk('drive')->putFileAs('Backups', new File($local), $filename);
                $this->info("Uploaded {$filename} to Drive.");
                $this->pruneDrive();
            } catch (\Throwable $e) {
                $this->warn("Drive upload failed: {$e->getMessage()}. Local backup kept.");
            }
        } else {
            $this->line('Drive upload skipped (creds missing or --skip-drive).');
        }

        return self::SUCCESS;
    }

    private function driveConfigured(): bool
    {
        foreach (['GOOGLE_DRIVE_CLIENT_ID', 'GOOGLE_DRIVE_CLIENT_SECRET', 'GOOGLE_DRIVE_REFRESH_TOKEN', 'GOOGLE_DRIVE_FOLDER'] as $k) {
            if (empty(env($k))) {
                return false;
            }
        }
        return true;
    }

    public function pruneLocal(string $dir): int
    {
        $cutoff = now()->subDays(7)->timestamp;
        $pruned = 0;
        if (! is_dir($dir)) {
            return 0;
        }
        foreach ((new Finder)->files()->in($dir)->name('*.sql.gz') as $file) {
            if ($file->getMTime() < $cutoff) {
                @unlink($file->getRealPath());
                $this->line("Pruned local: {$file->getFilename()}");
                $pruned++;
            }
        }
        return $pruned;
    }

    private function pruneDrive(): void
    {
        $cutoff = now()->subDays(30);
        foreach (Storage::disk('drive')->files('Backups') as $path) {
            $mtime = Storage::disk('drive')->lastModified($path);
            if ($mtime && Carbon::createFromTimestamp($mtime)->lt($cutoff)) {
                Storage::disk('drive')->delete($path);
                $this->line("Pruned Drive: {$path}");
            }
        }
    }
}
