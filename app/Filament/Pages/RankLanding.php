<?php

namespace App\Filament\Pages;

use App\Rank\RankRegistry;
use Filament\Pages\Page;

class RankLanding extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'All rank tools';

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'rank';

    protected static ?string $title = 'Rank';

    protected static string $view = 'filament.pages.rank-landing';

    public static function canAccess(): bool
    {
        return RankRegistry::canAccess(auth()->user());
    }

    public function getPrimaryCard(): ?array
    {
        foreach (RankRegistry::accessibleFor(auth()->user()) as $c) {
            if (($c['group'] ?? null) === 'primary') {
                return $c;
            }
        }
        return null;
    }

    public function getManageCards(): array
    {
        return array_values(array_filter(
            RankRegistry::accessibleFor(auth()->user()),
            fn (array $c) => ($c['group'] ?? null) === 'manage',
        ));
    }
}
