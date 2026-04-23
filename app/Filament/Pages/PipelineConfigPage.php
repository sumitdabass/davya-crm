<?php
// app/Filament/Pages/PipelineConfigPage.php
namespace App\Filament\Pages;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\StageTransitionRule;
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

    public function reorderStages(array $orderedIds): void
    {
        if (! static::canAccess()) abort(403);
        try {
            app(StageRepository::class)->reorder($this->getPipeline(), array_map('intval', $orderedIds));
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Reorder failed')
                ->body(collect($e->errors())->flatten()->first())
                ->danger()->send();
        }
    }

    public function getTransitionRules(): \Illuminate\Support\Collection
    {
        return $this->getPipeline()->transitionRules()->with(['fromStage','toStage','conditions'])->orderBy('id')->get();
    }

    public function saveRule(array $data, ?int $ruleId = null): void
    {
        if (! static::canAccess()) abort(403);

        // Reject rules with both sides NULL — the condition would apply to every transition
        // and is never what the admin intended.
        if (empty($data['from_stage_id']) && empty($data['to_stage_id'])) {
            Notification::make()
                ->title('Rule must specify at least one side (from or to stage).')
                ->danger()
                ->send();
            return;
        }

        $rule = $ruleId
            ? StageTransitionRule::findOrFail($ruleId)
            : new StageTransitionRule(['pipeline_id' => $this->getPipeline()->id]);

        $rule->fill([
            'name'          => $data['name'],
            'from_stage_id' => $data['from_stage_id'] ?: null,
            'to_stage_id'   => $data['to_stage_id'] ?: null,
            'severity'      => $data['severity'] ?? 'HARD',
            'is_active'     => (bool) ($data['is_active'] ?? true),
        ]);
        $rule->save();

        // Replace conditions
        $rule->conditions()->delete();
        foreach ($data['conditions'] ?? [] as $i => $c) {
            $rule->conditions()->create([
                'condition_type'    => $c['condition_type'],
                'field_or_relation' => $c['field_or_relation'],
                'operator'          => $c['operator'],
                'value'             => $c['value'] ?? null,
                'display_order'     => $i,
            ]);
        }

        app(PipelineConfig::class)->invalidate();
        Notification::make()->title('Rule saved')->success()->send();
    }

    public function toggleRule(int $ruleId): void
    {
        if (! static::canAccess()) abort(403);
        $rule = StageTransitionRule::findOrFail($ruleId);
        $rule->update(['is_active' => ! $rule->is_active]);
        app(PipelineConfig::class)->invalidate();
        Notification::make()
            ->title($rule->is_active ? 'Rule activated' : 'Rule deactivated')
            ->success()
            ->send();
    }

    public function deleteRule(int $ruleId): void
    {
        if (! static::canAccess()) abort(403);
        StageTransitionRule::findOrFail($ruleId)->delete();
        app(PipelineConfig::class)->invalidate();
        Notification::make()->title('Rule deleted')->success()->send();
    }
}
