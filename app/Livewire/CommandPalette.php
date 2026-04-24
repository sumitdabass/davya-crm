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
        $user = auth()->user();
        $isAdmin = $user?->hasRole('admin') ?? false;
        $isHead = $user?->hasRole('head') ?? false;
        $isFinance = $user?->hasRole('finance') ?? false;

        $all = [
            ['label' => 'Pipeline',                     'url' => '/admin/kanban',          'visible' => true],
            ['label' => 'Students',                     'url' => '/admin/students',        'visible' => true],
            ['label' => 'Today',                        'url' => '/admin/today',           'visible' => true],
            ['label' => 'Dashboard',                    'url' => '/admin',                 'visible' => true],
            ['label' => 'Reports — Leads',              'url' => '/admin/leads-report',    'visible' => $isAdmin],
            ['label' => 'Reports — Payments',           'url' => '/admin/payment-report',  'visible' => $isAdmin || $isHead],
            ['label' => 'Reports — Activity',           'url' => '/admin/activity-audit',  'visible' => $isAdmin],
            ['label' => 'Reports — Duplicate review',   'url' => '/admin/duplicate-flags', 'visible' => $isAdmin],
            ['label' => 'Reports — Lead import',        'url' => '/admin/lead-import',     'visible' => $isAdmin],
            ['label' => 'Finance — Expenses',           'url' => '/admin/expenses',        'visible' => $isAdmin || $isFinance],
            ['label' => 'Finance — Investments',        'url' => '/admin/investments',     'visible' => $isAdmin || $isFinance],
            ['label' => 'Settings — Fields',            'url' => '/admin/student-fields',  'visible' => $isAdmin],
            ['label' => 'Settings — Stages',            'url' => '/admin/pipeline-config', 'visible' => $isAdmin],
            ['label' => 'Settings',                     'url' => '/admin/settings',        'visible' => $isAdmin],
        ];

        $visible = array_values(array_filter($all, fn ($p) => $p['visible']));

        if ($this->query === '') {
            return $visible;
        }

        return array_values(array_filter($visible, fn ($p) => stripos($p['label'], $this->query) !== false));
    }

    public function render()
    {
        return view('livewire.command-palette', [
            'students' => $this->students(),
            'pages'    => $this->pages(),
        ]);
    }
}
