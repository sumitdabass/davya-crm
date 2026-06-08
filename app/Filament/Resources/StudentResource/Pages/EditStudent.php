<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Filament\Support\PaymentPayoutChooser;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use App\StudentFields\StudentFormDynamicTrait;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditStudent extends EditRecord
{
    use StudentFormDynamicTrait;

    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentPayoutChooser::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->hydrateCustomFields($this->record, $data);
    }

    protected function afterSave(): void
    {
        $this->persistCustomFields($this->record, $this->data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Custom fields are persisted separately via afterSave(); strip from
        // the column-mapped payload so Filament doesn't try to write them
        // as a `students.custom_fields` column.
        unset($data['custom_fields']);

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
        $data['stage'] = $target->name;  // Dual-write: keep the legacy VARCHAR cache in sync.

        // Lead Owner lock: once a head saves a referrer_id, lock the field so
        // only an admin can change it later (admin clears the lock manually).
        $caller = auth()->user();
        if ($caller?->hasRole('head') && ! $caller->hasRole('admin')
            && $this->record->referrer_id_locked_at === null
            && ! empty($data['referrer_id'])) {
            $data['referrer_id_locked_at'] = now();
        }

        return $data;
    }
}
