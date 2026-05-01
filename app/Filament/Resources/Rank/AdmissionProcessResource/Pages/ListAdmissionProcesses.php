<?php

namespace App\Filament\Resources\Rank\AdmissionProcessResource\Pages;

use App\Filament\Resources\Rank\AdmissionProcessResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdmissionProcesses extends ListRecords
{
    protected static string $resource = AdmissionProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
