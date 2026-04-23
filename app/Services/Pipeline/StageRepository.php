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
        $name = trim($name);

        if (! in_array($type, Stage::TYPES, true)) {
            throw ValidationException::withMessages([
                'stage_type' => "Invalid stage type '$type'. Allowed: " . implode(', ', Stage::TYPES) . '.',
            ]);
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
        $newName = trim($newName);

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
        // Guard: the reorder list must exactly match the pipeline's current stages.
        // Partial or foreign IDs would leave display_order in a half-updated, duplicated state.
        $owned = $pipeline->stages()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = collect($orderedStageIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($owned !== $given) {
            throw ValidationException::withMessages([
                'stages' => "Reorder list must contain exactly the pipeline's current stage IDs.",
            ]);
        }

        DB::transaction(function () use ($pipeline, $orderedStageIds) {
            foreach ($orderedStageIds as $i => $id) {
                $pipeline->stages()->where('id', $id)->update(['display_order' => $i + 1]);
            }
        });
        $this->config->invalidate();
    }

    public function delete(Stage $stage, ?int $transferToStageId = null): void
    {
        $studentCount = Student::where('stage_id', $stage->id)->count();

        if ($studentCount > 0 && $transferToStageId === null) {
            throw ValidationException::withMessages([
                'transfer_to_stage_id' => "Stage has $studentCount student(s). Choose a target stage to move them to before deleting.",
            ]);
        }

        if ($studentCount > 0) {
            if ($transferToStageId === $stage->id) {
                throw ValidationException::withMessages(['transfer_to_stage_id' => 'Cannot transfer to the same stage.']);
            }
            $target = $stage->pipeline->stages()->where('id', $transferToStageId)->first();
            if (! $target) {
                throw ValidationException::withMessages([
                    'transfer_to_stage_id' => 'Target stage not found in this pipeline.',
                ]);
            }

            DB::transaction(function () use ($stage, $target) {
                Student::where('stage_id', $stage->id)->update([
                    'stage_id' => $target->id,
                    'stage' => $target->name,
                ]);
                $stage->delete();
            });
        } else {
            $stage->delete();
        }

        $this->config->invalidate();
    }

    public function changeType(Stage $stage, string $newType): Stage
    {
        if (! in_array($newType, Stage::TYPES, true)) {
            throw ValidationException::withMessages([
                'stage_type' => "Invalid stage type '$newType'. Allowed: " . implode(', ', Stage::TYPES) . '.',
            ]);
        }
        $stage->update(['stage_type' => $newType]);
        $this->config->invalidate();
        return $stage->fresh();
    }
}
