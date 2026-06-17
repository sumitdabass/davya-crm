<?php

namespace App\Filament\Pages\Rank;

class DtuPredict extends AbstractRankPredict
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'DTU — Predict';

    protected static ?string $title = 'DTU Rank Predictor';

    protected static ?string $slug = 'rank/dtu/predict';

    protected static ?int $navigationSort = 2;

    protected function datasetToken(): string
    {
        return 'dtu';
    }

    protected function showsYearComparison(): bool
    {
        return true;
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canRankPredict('dtu');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
