<?php

namespace App\Finance;

use App\Models\Expense;
use App\Models\Investment;
use App\Models\Note;
use App\Models\User;

class FinanceRegistry
{
    public static function descriptors(): array
    {
        return [
            [
                'key' => 'expenses',
                'title' => 'Expenses',
                'desc' => 'Day-to-day spend captured via the Slack pipeline or manual entry — categorised, dated, and audit-logged.',
                'icon' => 'heroicon-o-banknotes',
                'url' => '/admin/expenses',
                'policy' => Expense::class,
            ],
            [
                'key' => 'investments',
                'title' => 'Investments',
                'desc' => 'Money in / out for assets and capital movements — directional (in/out), audit-logged, separate from operating expense.',
                'icon' => 'heroicon-o-chart-bar',
                'url' => '/admin/investments',
                'policy' => Investment::class,
            ],
            [
                'key' => 'notes',
                'title' => 'Notes',
                'desc' => 'Standalone notes captured via the Slack pipeline ("note …") or manual entry — no amount, no ledger.',
                'icon' => 'heroicon-o-pencil-square',
                'url' => '/admin/notes',
                'policy' => Note::class,
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
            fn (array $d) => $user->can('viewAny', $d['policy']),
        ));
    }

    public static function anyAccessibleFor(?User $user): bool
    {
        return self::accessibleFor($user) !== [];
    }
}
