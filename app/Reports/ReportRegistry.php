<?php

namespace App\Reports;

use App\Filament\Pages\ActivityAudit;
use App\Filament\Pages\DuplicateFlagsReview;
use App\Filament\Pages\LeadsReport;
use App\Filament\Pages\PaymentReport;
use App\Models\User;

class ReportRegistry
{
    public static function descriptors(): array
    {
        return [
            [
                'key'   => 'leads',
                'title' => 'Leads report',
                'desc'  => 'Pipeline-status counts by owner and lead source, plus monthly staff performance scoring.',
                'icon'  => 'heroicon-o-users',
                'url'   => '/admin/leads-report',
                'gate'  => LeadsReport::class,
            ],
            [
                'key'   => 'payment',
                'title' => 'Payment report',
                'desc'  => 'Received / refunded / net collected with sparklines, owner+type breakdown, today\'s payments tab.',
                'icon'  => 'heroicon-o-banknotes',
                'url'   => '/admin/payments-report',
                'gate'  => PaymentReport::class,
            ],
            [
                'key'   => 'duplicate',
                'title' => 'Duplicate review',
                'desc'  => 'Resolve flagged duplicate phones — keep one, reassign payments / notes / round history, delete the loser.',
                'icon'  => 'heroicon-o-document-duplicate',
                'url'   => '/admin/duplicate-flags',
                'gate'  => DuplicateFlagsReview::class,
            ],
            [
                'key'   => 'activity',
                'title' => 'Activity audit',
                'desc'  => 'Spatie ActivityLog browser — every create / update / delete on Student, User, Payment.',
                'icon'  => 'heroicon-o-clipboard-document-list',
                'url'   => '/admin/activity-audit',
                'gate'  => ActivityAudit::class,
            ],
        ];
    }

    public static function accessibleFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            self::descriptors(),
            fn (array $d) => $d['gate']::canAccess(),
        ));
    }

    public static function anyAccessibleFor(?User $user): bool
    {
        return self::accessibleFor($user) !== [];
    }
}
