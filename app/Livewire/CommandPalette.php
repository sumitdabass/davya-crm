<?php

namespace App\Livewire;

use App\Models\Student;
use Livewire\Attributes\On;
use Livewire\Component;

class CommandPalette extends Component
{
    public bool $isOpen = false;
    public string $query = '';

    #[On('open-command-palette')]
    public function open(): void
    {
        $this->isOpen = true;
        $this->query = '';
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function students(): array
    {
        if (mb_strlen($this->query) < 2) {
            return [];
        }
        $q = $this->query;
        $user = auth()->user();

        $query = Student::query();
        // Use the visibleTo scope if it exists (defined in Student model per memory).
        if (method_exists(Student::class, 'scopeVisibleTo')) {
            $query = $query->visibleTo($user);
        }

        return $query
            ->where(function ($qq) use ($q) {
                $qq->where('name', 'LIKE', "%{$q}%")
                   ->orWhere('phone', 'LIKE', "%{$q}%");
            })
            ->limit(8)
            ->get(['id', 'name', 'phone', 'course'])
            ->toArray();
    }

    public function pages(): array
    {
        $all = [
            ['label' => 'Pipeline',                     'url' => '/admin/kanban'],
            ['label' => 'Students',                     'url' => '/admin/students'],
            ['label' => 'Today',                        'url' => '/admin/today'],
            ['label' => 'Dashboard',                    'url' => '/admin'],
            ['label' => 'Reports — Leads',              'url' => '/admin/leads-report'],
            ['label' => 'Reports — Activity',           'url' => '/admin/activity-audit'],
            ['label' => 'Reports — Duplicate review',   'url' => '/admin/duplicate-flags'],
            ['label' => 'Reports — Lead import',        'url' => '/admin/lead-import'],
            ['label' => 'Settings — Fields',            'url' => '/admin/student-fields'],
            ['label' => 'Settings — Stages',            'url' => '/admin/pipeline-config'],
            ['label' => 'Settings',                      'url' => '/admin/settings'],
        ];

        if ($this->query === '') {
            return $all;
        }

        return array_values(array_filter($all, fn ($p) => stripos($p['label'], $this->query) !== false));
    }

    public function render()
    {
        return view('livewire.command-palette', [
            'students' => $this->students(),
            'pages'    => $this->pages(),
        ]);
    }
}
