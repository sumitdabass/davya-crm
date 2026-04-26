<?php

namespace App\Livewire;

use App\Models\Student;
use Livewire\Component;

class StudentSearch extends Component
{
    public string $query = '';

    public function students(): array
    {
        $q = trim($this->query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $builder = Student::query();
        if (method_exists(Student::class, 'scopeVisibleTo')) {
            $builder = $builder->visibleTo(auth()->user());
        }

        $like = "%{$q}%";

        return $builder
            ->where(function ($qq) use ($like) {
                $qq->where('name', 'LIKE', $like)
                   ->orWhere('phone', 'LIKE', $like)
                   ->orWhere('phone_2', 'LIKE', $like)
                   ->orWhere('email', 'LIKE', $like)
                   ->orWhere('father_name', 'LIKE', $like)
                   ->orWhere('ipu_user_id', 'LIKE', $like)
                   ->orWhere('course', 'LIKE', $like);
            })
            ->orderBy('updated_at', 'desc')
            ->limit(8)
            ->get(['id', 'name', 'phone', 'course', 'stage'])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.student-search', [
            'students' => $this->students(),
        ]);
    }
}
