<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Console\Command;

class BackfillRankProbabilityCommand extends Command
{
    protected $signature = 'performance:backfill-rank-probabilities {--chunk=100}';

    protected $description = 'Recompute rank_prob_first_choice for all students. Idempotent — safe to re-run.';

    public function handle(StudentChoicePredictor $predictor): int
    {
        $chunk = (int) $this->option('chunk');
        $touched = 0;
        $cleared = 0;
        $skipped = 0;

        Student::query()->orderBy('id')->chunkById($chunk, function ($students) use ($predictor, &$touched, &$cleared, &$skipped) {
            foreach ($students as $student) {
                if (empty($student->rank)) {
                    if ($student->rank_prob_first_choice !== null) {
                        $student->rank_prob_first_choice = null;
                        $student->saveQuietly();
                        $cleared++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $choices = $predictor->topChoices($student, 1);
                $value = $choices === [] ? null : (int) $choices[0]['probability_pct'];

                if ($student->rank_prob_first_choice !== $value) {
                    $student->rank_prob_first_choice = $value;
                    $student->saveQuietly();
                }
                $touched++;
            }
        });

        $this->info("Backfill complete — touched=$touched cleared=$cleared skipped=$skipped");

        return self::SUCCESS;
    }
}
