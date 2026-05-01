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
        ];

        if ($user?->hasRole('admin')) {
            $tabs[] = ['key' => 'reports', 'label' => 'Reports', 'url' => '/admin/leads-report', 'match' => '/admin/leads-report'];
        }

        if ($user?->hasAnyRole(['admin', 'finance'])) {
            $tabs[] = ['key' => 'finance', 'label' => 'Finance', 'url' => '/admin/expenses', 'match' => '/admin/expenses'];
        }

        if ($user?->hasAnyRole(['admin', 'rank-admin'])) {
            $tabs[] = ['key' => 'rank', 'label' => 'Rank', 'url' => '/admin/rank-lookup', 'match' => '/admin/rank'];
        }

        return $tabs;
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.top-bar', [
            'tabs' => $this->tabs(),
            'currentPath' => request()->path(),
            'user' => $user,
            'canSettings' => $user?->hasRole('admin') ?? false,
        ]);
    }
}
