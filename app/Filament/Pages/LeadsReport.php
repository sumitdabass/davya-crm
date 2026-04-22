<?php

namespace App\Filament\Pages;

use App\Services\PipelineSummary;
use Filament\Pages\Page;

class LeadsReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Leads report';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.leads-report';

    protected static ?string $slug = 'leads-report';

    protected static ?string $title = 'Leads Report';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array{byOwner:array<int,array<string,mixed>>, byReferrer:array<int,array<string,mixed>>, totals:array<string,int>}
     */
    public function getReport(): array
    {
        $byOwner = PipelineSummary::byOwnerAfterCaptured();
        $byReferrer = PipelineSummary::byReferrerAfterCaptured();

        return [
            'byOwner'    => $byOwner,
            'byReferrer' => $byReferrer,
            'totals'     => [
                'owners_counted'    => count($byOwner),
                'referrers_counted' => count($byReferrer),
                'past_capture'      => array_sum(array_column($byOwner, 'count')),
            ],
        ];
    }
}
