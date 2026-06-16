<?php

namespace App\Console\Commands\Rank;

use App\Services\Rank\JacCutoffImporter;
use Illuminate\Console\Command;

class ImportJacCutoffs extends Command
{
    protected $signature = 'rank:import-jac {--file= : Path to the parsed JAC cutoffs CSV} {--year= : Admission year}';

    protected $description = 'Import JAC Delhi (DTU/NSUT/IGDTUW) cutoffs from a parsed CSV into the rank cutoffs table.';

    public function handle(JacCutoffImporter $importer): int
    {
        $file = (string) $this->option('file');
        $year = (int) $this->option('year');
        if ($file === '' || $year === 0) {
            $this->error('Both --file and --year are required.');

            return self::FAILURE;
        }

        $summary = $importer->import($file, $year);
        $this->info("Imported {$summary['imported']} cutoffs, skipped {$summary['skipped']}.");

        return self::SUCCESS;
    }
}
