<?php

namespace App\Livewire;

use Livewire\Component;

class TopBar extends Component
{
    public function tabs(): array
    {
        $user = auth()->user();

        $tabs = [
            ['key' => 'pipeline', 'label' => 'Pipeline', 'url' => '/admin/kanban',         'match' => '/admin/kanban'],
            ['key' => 'students', 'label' => 'Students', 'url' => '/admin/students',       'match' => '/admin/students'],
            ['key' => 'today',    'label' => 'Today',    'url' => '/admin/today',          'match' => '/admin/today'],
            ['key' => 'reports',  'label' => 'Reports',  'url' => '/admin/leads-report',   'match' => '/admin/leads-report'],
        ];

        if ($user?->hasAnyRole(['admin', 'finance'])) {
            $tabs[] = ['key' => 'finance', 'label' => 'Finance', 'url' => '/admin/expenses', 'match' => '/admin/expenses'];
        }

        return $tabs;
    }

    public function render()
    {
        return view('livewire.top-bar', [
            'tabs' => $this->tabs(),
            'currentPath' => request()->path(),
            'user' => auth()->user(),
        ]);
    }
}
