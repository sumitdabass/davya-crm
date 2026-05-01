<?php

namespace App\Filament\Resources\Rank\QualifyingExamResource\Pages;

use App\Filament\Resources\Rank\QualifyingExamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQualifyingExams extends ListRecords
{
    protected static string $resource = QualifyingExamResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
