<?php
namespace App\StudentFields;

use App\Models\Student;
use App\StudentFields\StudentFormDynamicTrait\Hydrator;
use App\StudentFields\StudentFormDynamicTrait\Persister;

trait StudentFormDynamicTrait
{
    protected function hydrateCustomFields(Student $student, array $formData): array
    {
        $formData['custom_fields'] = (new Hydrator())->hydrate($student);
        return $formData;
    }

    protected function persistCustomFields(Student $student, array $formData): void
    {
        $custom = $formData['custom_fields'] ?? [];
        if (!is_array($custom) || !$custom) return;
        (new Persister())->persist($student, $custom);
    }
}
