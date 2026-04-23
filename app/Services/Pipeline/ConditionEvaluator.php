<?php
// app/Services/Pipeline/ConditionEvaluator.php
namespace App\Services\Pipeline;

use App\Models\StageTransitionCondition;
use App\Models\Student;

class ConditionEvaluator
{
    public function passes(StageTransitionCondition $cond, Student $student): bool
    {
        return match ($cond->condition_type) {
            'FIELD_CHECK'  => $this->checkField($cond, $student),
            'HAS_RELATION' => $this->checkRelation($cond, $student),
            default        => false,
        };
    }

    private function checkField(StageTransitionCondition $cond, Student $student): bool
    {
        $value = $student->getAttribute($cond->field_or_relation);
        $rhs   = is_array($cond->value) ? ($cond->value['rhs'] ?? null) : null;

        return match ($cond->operator) {
            'is_empty'     => $value === null || $value === '',
            'is_not_empty' => $value !== null && $value !== '',
            '='            => $value == $rhs,
            '!='           => $value != $rhs,
            '>'            => $value !== null && $value >  $rhs,
            '<'            => $value !== null && $value <  $rhs,
            '>=', '≥'      => $value !== null && $value >= $rhs,
            '<=', '≤'      => $value !== null && $value <= $rhs,
            default        => false,
        };
    }

    private function checkRelation(StageTransitionCondition $cond, Student $student): bool
    {
        $relation = $cond->field_or_relation;
        if (! method_exists($student, $relation)) return false;

        $filters = is_array($cond->value) ? $cond->value : [];
        $q = $student->{$relation}();

        foreach ($filters as $key => $val) {
            if ($key === 'count_min') continue;
            if (str_ends_with($key, '_gte')) {
                $col = substr($key, 0, -4);
                $q->where($col, '>=', $val === 'now' ? now() : $val);
            } elseif (str_ends_with($key, '_lte')) {
                $col = substr($key, 0, -4);
                $q->where($col, '<=', $val === 'now' ? now() : $val);
            } elseif (str_ends_with($key, '_like')) {
                $col = substr($key, 0, -5);
                $q->where($col, 'like', $val);
            } else {
                $q->where($key, '=', $val);
            }
        }

        $countMin = (int) ($filters['count_min'] ?? 1);
        return $q->count() >= $countMin;
    }
}
