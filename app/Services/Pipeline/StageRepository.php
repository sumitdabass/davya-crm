<?php
// app/Services/Pipeline/StageRepository.php
namespace App\Services\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageRepository
{
    public const MAX_STAGES_PER_PIPELINE = 20;

    public function __construct(private readonly PipelineConfig $config) {}

    public function create(Pipeline $pipeline, string $name, string $type, ?string $description = null): Stage
    {
        if (! in_array($type, Stage::TYPES, true)) {
            throw ValidationException::withMessages(['stage_type' => "Invalid stage type: $type"]);
        }

        if ($pipeline->stages()->count() >= self::MAX_STAGES_PER_PIPELINE) {
            throw ValidationException::withMessages([
                'stages' => 'Cannot create stage — pipeline already has the maximum of 20 stages.',
            ]);
        }

        if ($pipeline->stages()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => "A stage named \"$name\" already exists in this pipeline.",
            ]);
        }

        $nextOrder = ((int) $pipeline->stages()->max('display_order')) + 1;

        $stage = $pipeline->stages()->create([
            'name' => $name,
            'stage_type' => $type,
            'description' => $description,
            'display_order' => $nextOrder,
        ]);

        $this->config->invalidate();
        return $stage;
    }

    public function rename(Stage $stage, string $newName): Stage
    {
        if ($stage->pipeline->stages()->where('name', $newName)->where('id', '!=', $stage->id)->exists()) {
            throw ValidationException::withMessages([
                'name' => "A stage named \"$newName\" already exists.",
            ]);
        }
        $stage->update(['name' => $newName]);
        $this->config->invalidate();
        return $stage;
    }

    /** @param int[] $orderedStageIds */
    public function reorder(Pipeline $pipeline, array $orderedStageIds): void
    {
        DB::transaction(function () use ($pipeline, $orderedStageIds) {
            foreach ($orderedStageIds as $i => $id) {
                $pipeline->stages()->where('id', $id)->update(['display_order' => $i + 1]);
            }
        });
        $this->config->invalidate();
    }
}
