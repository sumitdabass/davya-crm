<?php

namespace App\Observers;

use App\Models\Student;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Support\Facades\Log;
use Throwable;

class StudentRankProbabilityObserver
{
    public function __construct(private readonly StudentChoicePredictor $predictor) {}

    public function creating(Student $student): void
    {
        $student->rank_prob_first_choice = $this->compute($student);
    }

    public function updating(Student $student): void
    {
        if (! $this->relevantAttributesChanged($student)) {
            return;
        }
        $student->rank_prob_first_choice = $this->compute($student);
    }

    private function relevantAttributesChanged(Student $student): bool
    {
        foreach (['rank', 'category', 'preference_r1'] as $attr) {
            if ($student->isDirty($attr)) {
                return true;
            }
        }

        return false;
    }

    private function compute(Student $student): ?int
    {
        if (empty($student->rank)) {
            return null;
        }

        try {
            $choices = $this->predictor->topChoices($student, 1);
        } catch (Throwable $e) {
            Log::warning('StudentRankProbabilityObserver: predictor failed; caching null', [
                'student_id' => $student->id,
                'rank' => $student->rank,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($choices === []) {
            return null;
        }

        return (int) $choices[0]['probability_pct'];
    }
}
