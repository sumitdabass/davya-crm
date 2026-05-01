<?php

namespace App\Filament\Resources\Rank\CutoffResource\Pages;

use App\Filament\Resources\Rank\CutoffResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCutoffs extends ListRecords
{
    protected static string $resource = CutoffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkPaste')
                ->label('Bulk Paste')
                ->icon('heroicon-o-clipboard-document')
                ->color('warning')
                ->url(fn () => CutoffResource::getUrl('paste')),
            CreateAction::make(),
        ];
    }
}
