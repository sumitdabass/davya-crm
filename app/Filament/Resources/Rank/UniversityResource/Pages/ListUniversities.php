<?php

namespace App\Filament\Resources\Rank\UniversityResource\Pages;

use App\Filament\Resources\Rank\UniversityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUniversities extends ListRecords
{
    protected static string $resource = UniversityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
