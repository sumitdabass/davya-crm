<?php
// app/Filament/Pages/PipelineConfigPage.php
namespace App\Filament\Pages;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageRepository;
use Filament\Notifications\Notification;
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
        return app(PipelineConfig::class)->defaultPipeline();
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

    public function createStage(string $name, string $type): void
    {
        if (! static::canAccess()) abort(403);
        try {
            app(StageRepository::class)->create($this->getPipeline(), $name, $type);
            Notification::make()->title("Stage \"$name\" created")->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()->title('Could not create stage')->body(collect($e->errors())->flatten()->first())->danger()->send();
        }
    }

    public function renameStage(int $stageId, string $newName): void
    {
        if (! static::canAccess()) abort(403);
        $stage = Stage::findOrFail($stageId);
        try {
            app(StageRepository::class)->rename($stage, $newName);
            Notification::make()->title('Renamed')->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()->title('Rename failed')->body(collect($e->errors())->flatten()->first())->danger()->send();
        }
    }

    public function deleteStage(int $stageId, ?int $transferTo = null): void
    {
        if (! static::canAccess()) abort(403);
        $stage = Stage::findOrFail($stageId);
        try {
            app(StageRepository::class)->delete($stage, $transferTo);
            Notification::make()->title('Stage deleted')->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()->title('Cannot delete')->body(collect($e->errors())->flatten()->first())->warning()->send();
        }
    }

    public function changeStageType(int $stageId, string $newType): void
    {
        if (! static::canAccess()) abort(403);
        $stage = Stage::findOrFail($stageId);
        try {
            app(StageRepository::class)->changeType($stage, $newType);
            Notification::make()->title('Type changed')->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()->title('Change failed')->body(collect($e->errors())->flatten()->first())->danger()->send();
        }
    }
}
