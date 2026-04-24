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
        return Student::with(['owner'])->find($this->studentId);
    }

    public function render()
    {
        return view('livewire.student-peek-drawer');
    }
}
