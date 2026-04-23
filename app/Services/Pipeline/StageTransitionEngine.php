<?php
// app/Services/Pipeline/StageTransitionEngine.php
namespace App\Services\Pipeline;

use App\Models\StageTransitionCondition;
use App\Models\StageTransitionRule;
use App\Models\Student;
use Illuminate\Support\Collection;

class StageTransitionEngine
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
        private readonly PipelineConfig $config,
    ) {}

    /**
     * Back-compat shim for MeetingObserver and any other legacy callers —
     * round-change soft warnings are not yet modeled as DB rules.
     *
     * @return string[]
     */
    public function forRoundChange(Student $student, string $newRound): array
    {
        return [];
    }

    /** @return array{hard: string[], soft: string[], hard_missing_fields: string[], soft_missing_fields: string[]} */
    public function forStageChange(Student $student, int $toStageId): array
    {
        $fromStageId = $student->stage_id;
        $pipelineId  = $this->config->defaultPipelineId();

        $rules = $this->matchingRules($pipelineId, $fromStageId, $toStageId);

        $hard = [];
        $soft = [];
        $hardFields = [];
        $softFields = [];

        foreach ($rules as $rule) {
            $failures = $this->failingConditions($rule, $student);
            if (empty($failures['messages'])) continue;

            $message = $this->humanMessage($rule, $failures['messages']);
            if ($rule->severity === StageTransitionRule::SEV_HARD) {
                $hard[] = $message;
                $hardFields = array_merge($hardFields, $failures['fields']);
            } else {
                $soft[] = $message;
                $softFields = array_merge($softFields, $failures['fields']);
            }
        }

        return [
            'hard' => $hard,
            'soft' => $soft,
            'hard_missing_fields' => array_values(array_unique($hardFields)),
            'soft_missing_fields' => array_values(array_unique($softFields)),
        ];
    }

    /** @return Collection<int,StageTransitionRule> */
    private function matchingRules(int $pipelineId, ?int $fromStageId, int $toStageId): Collection
    {
        return StageTransitionRule::query()
            ->with('conditions')
            ->where('pipeline_id', $pipelineId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('from_stage_id')->orWhere('from_stage_id', $fromStageId))
            ->where(fn ($q) => $q->whereNull('to_stage_id')->orWhere('to_stage_id', $toStageId))
            // Skip rules where both sides NULL — meaningless (guarded at DB level by CHECK, but be defensive).
            ->where(fn ($q) => $q->whereNotNull('from_stage_id')->orWhereNotNull('to_stage_id'))
            ->get();
    }

    /** @return array{messages: string[], fields: string[]} */
    private function failingConditions(StageTransitionRule $rule, Student $student): array
    {
        $messages = [];
        $fields = [];
        foreach ($rule->conditions as $cond) {
            if (! $this->evaluator->passes($cond, $student)) {
                $messages[] = $this->describeCondition($cond);
                // Only field checks are user-fixable via inline modal; relation checks (e.g. "at least one payment") can't be filled inline
                if ($cond->condition_type === 'FIELD_CHECK') {
                    $fields[] = $cond->field_or_relation;
                }
            }
        }
        return ['messages' => $messages, 'fields' => $fields];
    }

    private function describeCondition(StageTransitionCondition $cond): string
    {
        if ($cond->condition_type === 'FIELD_CHECK') {
            if ($cond->operator === 'is_not_empty') {
                return "{$cond->field_or_relation} is required";
            }
            if ($cond->operator === 'is_empty') {
                return "{$cond->field_or_relation} must be empty";
            }
            $rhs = is_array($cond->value) ? ($cond->value['rhs'] ?? '') : '';
            return "{$cond->field_or_relation} {$cond->operator} $rhs";
        }
        // HAS_RELATION
        $min = (int) ($cond->value['count_min'] ?? 1);
        return "record needs at least $min {$cond->field_or_relation}";
    }

    private function humanMessage(StageTransitionRule $rule, array $failures): string
    {
        return "[{$rule->name}] " . implode('; ', $failures) . '.';
    }
}
