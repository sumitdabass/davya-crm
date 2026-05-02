<?php

namespace App\Livewire;

use App\Models\Student;
use Livewire\Attributes\On;
use Livewire\Component;

class StudentPeekDrawer extends Component
{
    public bool $isOpen = false;
    public ?int $studentId = null;
    public string $activeTab = 'overview';

    #[On('open-student-peek')]
    public function open(int $studentId): void
    {
        $query = Student::query();
        if (method_exists(Student::class, 'scopeVisibleTo')) {
            $query = $query->visibleTo(auth()->user());
        }
        $student = $query->find($studentId);
        if ($student === null) {
            return;
        }
        $this->studentId = $student->id;
        $this->isOpen = true;
        $this->activeTab = 'overview';
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->studentId = null;
        $this->activeTab = 'overview';
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['overview', 'payments', 'notes', 'meetings', 'activity'], true)
            ? $tab
            : 'overview';
    }

    public function getStudentProperty(): ?Student
    {
        if ($this->studentId === null) {
            return null;
        }
        return Student::with(['owner'])
            ->visibleTo(auth()->user())
            ->find($this->studentId);
    }

    /**
     * @return array<int, array{rank:int, college:string, branch:string, probability_pct:int, bucket:string}>
     */
    public function getChoicePredictionsProperty(): array
    {
        $student = $this->student;
        if ($student === null) {
            return [];
        }
        try {
            return app(\App\Services\Rank\StudentChoicePredictor::class)->topChoices($student, 3);
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }

    public function render()
    {
        return view('livewire.student-peek-drawer');
    }
}
