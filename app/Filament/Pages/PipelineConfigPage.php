<?php
// app/Filament/Pages/PipelineConfigPage.php
namespace App\Filament\Pages;

use App\Models\Pipeline;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageRepository;
use Filament\Pages\Page;

class PipelineConfigPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Pipeline Config';
    protected static ?string $title = 'Pipeline Configuration';
    protected static ?string $slug = 'pipeline-config';
    protected static string $view = 'filament.pages.pipeline-config';
    protected static ?int $navigationSort = 1;

    public string $activeTab = 'stages'; // 'stages' | 'rules'

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('admin');
    }

    public function getPipeline(): Pipeline
    {
        return Pipeline::default();
    }

    public function getStagesByType(): array
    {
        $config = app(PipelineConfig::class);
        return [
            'open' => $config->openStages(),
            'won'  => $config->wonStages(),
            'lost' => $config->lostStages(),
        ];
    }
}
