<?php

namespace App\Livewire;

use App\Filament\Pages\FinanceLanding;
use App\Filament\Pages\ReportsLanding;
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

        if (ReportsLanding::canAccess()) {
            $tabs[] = ['key' => 'reports', 'label' => 'Reports', 'url' => '/admin/reports', 'match' => '/admin/reports'];
        }

        if (FinanceLanding::canAccess()) {
            $tabs[] = ['key' => 'finance', 'label' => 'Finance', 'url' => '/admin/finance', 'match' => '/admin/finance'];
        }

        if ($user?->hasAnyRole(['admin', 'rank-admin'])) {
            $tabs[] = ['key' => 'rank', 'label' => 'Rank', 'url' => '/admin/rank-lookup', 'match' => '/admin/rank'];
        }

        if (config('books.enabled') && $user?->isSuperAdmin()) {
            $tabs[] = ['key' => 'books', 'label' => 'Books', 'url' => '/admin/books', 'match' => '/admin/books'];
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
