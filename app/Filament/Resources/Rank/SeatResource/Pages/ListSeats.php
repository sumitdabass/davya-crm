<?php

namespace App\Filament\Resources\Rank\SeatResource\Pages;

use App\Filament\Resources\Rank\SeatResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeats extends ListRecords
{
    protected static string $resource = SeatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkPaste')
                ->label('Bulk Paste')
                ->icon('heroicon-o-clipboard-document')
                ->color('warning')
                ->url(fn () => SeatResource::getUrl('paste')),
            CreateAction::make(),
        ];
    }
}
