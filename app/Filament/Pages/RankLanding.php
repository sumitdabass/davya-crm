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

    /** @return array<int,array<string,string>> */
    public function getPredictCards(): array
    {
        return $this->cardsByGroup('predict');
    }

    /** @return array<int,array<string,string>> */
    public function getAnalyticsCards(): array
    {
        return $this->cardsByGroup('analytics');
    }

    /** @return array<int,array<string,string>> */
    public function getManageCards(): array
    {
        return $this->cardsByGroup('manage');
    }

    /** @return array<int,array<string,string>> */
    public function getLegacyCards(): array
    {
        return $this->cardsByGroup('legacy');
    }

    private function cardsByGroup(string $group): array
    {
        return array_values(array_filter(
            RankRegistry::cardsFor(auth()->user()),
            fn (array $c) => ($c['group'] ?? null) === $group,
        ));
    }
}
