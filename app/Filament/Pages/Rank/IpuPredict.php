<?php

namespace App\Filament\Pages\Rank;

class IpuPredict extends AbstractRankPredict
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'IPU — Predict';

    protected static ?string $title = 'IPU Rank Predictor';

    protected static ?string $slug = 'rank/ipu/predict';

    protected static ?int $navigationSort = 3;

    protected function datasetToken(): string
    {
        return 'ipu';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canRankPredict('ipu');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
