<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Services\StageTransitionValidator;
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

        $errors = (new StageTransitionValidator)->forStageChange($hypothetical, $data['stage']);
        if (! empty($errors)) {
            throw ValidationException::withMessages([
                'data.stage' => $errors[0],
            ]);
        }

        return $data;
    }
}
