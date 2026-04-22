<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class BackfillSumitSheet extends Command
{
    protected $signature = 'leads:backfill-sumit-sheet
                            {file : Path to the CSV export}
                            {--dry-run : Parse and normalize but do not insert}
                            {--as-user= : Email of the user to attribute as causer in activity_log (defaults to first admin)}';

    protected $description = 'Import Sumit website-form sheet export into students (owner=Sumit).';

    public function handle(LeadIntakeService $intake): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not readable: {$path}");
            return self::FAILURE;
        }

        $email  = (string) ($this->option('as-user') ?? '');
        $causer = $email !== ''
            ? User::where('email', $email)->first()
            : User::role('admin')->orderBy('id')->first();
        if ($causer === null) {
            $this->error($email !== '' ? "No user with email: {$email}" : 'No admin user found; use --as-user=<email>');
            return self::FAILURE;
        }
        Auth::login($causer);
        $this->info("Attributing activity to: {$causer->email}");

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open: {$path}");
            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            $this->error('CSV is empty');
            fclose($handle);
            return self::FAILURE;
        }
        $map = array_flip(array_map('trim', $header));

        $seen     = [];
        $imported = 0;
        $skipped  = 0;
        $rejected = 0;
        $dryRun   = (bool) $this->option('dry-run');

        while (($row = fgetcsv($handle)) !== false) {
            $get = fn (string $col) => isset($map[$col]) && isset($row[$map[$col]]) ? trim((string) $row[$map[$col]]) : '';

            $phoneRaw = $get('Phone');
            $course   = $get('Course');
            $phone    = $intake->normalizePhone($phoneRaw);

            if ($phone === null || $phone === '' || $course === '') {
                $rejected++;
                continue;
            }

            if (isset($seen[$phone])) {
                $skipped++;
                continue;
            }
            $seen[$phone] = true;

            if ($dryRun) {
                $imported++;
                continue;
            }

            $payload = [
                'phone'         => $phone,
                'course'        => $course,
                'name'          => $get('Name')         ?: null,
                'father_name'   => $get('Father Name')  ?: null,
                'twelfth_marks' => $get('12th marks')   ?: null,
                'rank'          => $get('Rank')         ?: null,
                'category'      => $this->normalizeCategory($get('Category')),
                'state'         => $get('State')        ?: null,
                'college'       => $get('College')      ?: null,
                'email'         => $get('Email')        ?: null,
                'referrer_name' => $get('Reference')    ?: null,
                'remarks'       => $get('Remarks')      ?: null,
                'source'        => $get('Source')       ?: 'Sheet:Sumit',
                'owner_name'    => 'Sumit',
            ];

            $result = $intake->ingest($payload);
            if ($result['duplicate'] ?? false) {
                $skipped++;
            } else {
                $imported++;
            }
        }

        fclose($handle);

        $this->info("Imported: {$imported} | Skipped (duplicate): {$skipped} | Rejected (missing phone/course): {$rejected}");
        return self::SUCCESS;
    }

    private function normalizeCategory(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        return match (true) {
            $v === '' => null,
            in_array($v, ['d', 'delhi'], true) => 'Delhi',
            in_array($v, ['od', 'outside', 'outsider'], true) => 'Outside',
            default => null,
        };
    }
}
