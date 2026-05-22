<?php

namespace App\Livewire;

use App\Filament\Pages\ActivityAudit;
use App\Filament\Pages\DuplicateFlagsReview;
use App\Filament\Pages\LeadsReport;
use App\Filament\Pages\PaymentReport;
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

        $reportsUrl = $this->primaryReportsUrl();
        if ($reportsUrl !== null) {
            $tabs[] = ['key' => 'reports', 'label' => 'Reports', 'url' => $reportsUrl, 'match' => $reportsUrl];
        }

        if ($user?->hasAnyRole(['admin', 'finance'])) {
            $tabs[] = ['key' => 'finance', 'label' => 'Finance', 'url' => '/admin/expenses', 'match' => '/admin/expenses'];
        }

        if ($user?->hasAnyRole(['admin', 'rank-admin'])) {
            $tabs[] = ['key' => 'rank', 'label' => 'Rank', 'url' => '/admin/rank-lookup', 'match' => '/admin/rank'];
        }

        if (config('books.enabled') && $user?->isSuperAdmin()) {
            $tabs[] = ['key' => 'books', 'label' => 'Books', 'url' => '/admin/books', 'match' => '/admin/books'];
        }

        return $tabs;
    }

    private function primaryReportsUrl(): ?string
    {
        $candidates = [
            LeadsReport::class           => '/admin/leads-report',
            PaymentReport::class         => '/admin/payments-report',
            DuplicateFlagsReview::class  => '/admin/duplicate-flags',
            ActivityAudit::class         => '/admin/activity-audit',
        ];

        foreach ($candidates as $page => $url) {
            if ($page::canAccess()) {
                return $url;
            }
        }

        return null;
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
