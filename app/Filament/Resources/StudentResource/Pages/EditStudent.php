<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $hypothetical = clone $this->record;
        $hypothetical->fill($data);

        $target = app(PipelineConfig::class)->stageByName($data['stage']);
        if (! $target) {
            throw ValidationException::withMessages([
                'data.stage' => "Unknown stage: {$data['stage']}",
            ]);
        }

        // Engine reads $hypothetical->stage_id as the "from" stage — leave it
        // unchanged until after the hard-rule check passes.
        $out = app(StageTransitionEngine::class)->forStageChange($hypothetical, $target->id);
        if (! empty($out['hard'])) {
            throw ValidationException::withMessages([
                'data.stage' => $out['hard'][0],
            ]);
        }

        $data['stage_id'] = $target->id;

        return $data;
    }
}
