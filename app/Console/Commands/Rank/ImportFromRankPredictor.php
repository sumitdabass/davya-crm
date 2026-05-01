<?php

namespace App\Console\Commands\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use Illuminate\Console\Command;
use PDO;

class ImportFromRankPredictor extends Command
{
    protected $signature = 'rank:import-from-predictor
                            {--sqlite= : Path to rank-predictor SQLite (default: /Users/Sumit/davya-crm/rank/database/database.sqlite)}
                            {--exam=JEE_MAIN : Qualifying exam code (default JEE_MAIN)}
                            {--process=COUNSELLING : Default admission process code for non-sliding rounds}';

    protected $description = 'Import IPU B.Tech cutoffs from the standalone rank-predictor SQLite into the ranks DB.';

    public function handle(): int
    {
        $sqlite = $this->option('sqlite') ?: '/Users/Sumit/davya-crm/rank/database/database.sqlite';
        if (! file_exists($sqlite)) {
            $this->error("SQLite not found: $sqlite");

            return self::FAILURE;
        }

        $ipu = University::where('code', 'IPU')->firstOrFail();
        $btech = Course::where('university_id', $ipu->id)->where('name', 'B.Tech')->firstOrFail();
        $exam = QualifyingExam::where('code', $this->option('exam'))->firstOrFail();
        $counselling = AdmissionProcess::where('code', $this->option('process'))->firstOrFail();
        $sliding = AdmissionProcess::where('code', 'SLIDING')->firstOrFail();

        $pdo = new PDO("sqlite:$sqlite");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $instMap = [];
        $branchMap = [];

        foreach ($pdo->query('SELECT id, name FROM institutes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inst = Institute::firstOrCreate(
                ['university_id' => $ipu->id, 'name' => $row['name']],
            );
            $instMap[$row['id']] = $inst->id;
        }

        foreach ($pdo->query('SELECT id, name, family FROM branches')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $branch = Branch::firstOrCreate(
                ['course_id' => $btech->id, 'name' => $row['name']],
                ['family' => $row['family']],
            );
            $branchMap[$row['id']] = $branch->id;
        }

        $this->info('Mapped '.count($instMap).' institutes, '.count($branchMap).' branches.');

        $imported = 0;
        $skipped = 0;
        $stmt = $pdo->query('SELECT year_id, round, institute_id, branch_id, shift, region, min_rank, max_rank, source FROM cutoffs');
        foreach ($stmt as $row) {
            if (! isset($instMap[$row['institute_id']]) || ! isset($branchMap[$row['branch_id']])) {
                $skipped++;

                continue;
            }
            $process = $row['round'] === 'sliding' ? $sliding : $counselling;
            Cutoff::updateOrCreate(
                [
                    'university_id' => $ipu->id,
                    'course_id' => $btech->id,
                    'qualifying_exam_id' => $exam->id,
                    'admission_process_id' => $process->id,
                    'year' => $row['year_id'],
                    'round' => $row['round'],
                    'institute_id' => $instMap[$row['institute_id']],
                    'branch_id' => $branchMap[$row['branch_id']],
                    'shift' => $row['shift'],
                    'region' => $row['region'],
                ],
                [
                    'min_rank' => $row['min_rank'],
                    'max_rank' => $row['max_rank'],
                    'source' => $row['source'],
                ]
            );
            $imported++;
        }

        $this->info("Imported {$imported} cutoffs (skipped {$skipped}).");
        $this->info('Final counts in ranks DB:');
        $this->table(
            ['Year', 'Round', 'Region', 'Count'],
            Cutoff::selectRaw('year, round, region, count(*) as c')
                ->groupBy('year', 'round', 'region')
                ->orderBy('year')->orderBy('round')->orderBy('region')
                ->get()
                ->map(fn ($r) => [$r->year, $r->round, $r->region, $r->c])
                ->all()
        );

        return self::SUCCESS;
    }
}
