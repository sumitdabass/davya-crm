<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereNotIn('stage', ['Admission Confirmed', 'Closed'])),

            'counselling' => Tab::make('In Counselling')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('stage', [
                    'University Registration',
                    'Counselling In Progress',
                    'Seat Allotted',
                ])),

            'pending_payment' => Tab::make('Fee pending')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereRaw('COALESCE(deal_amount,0) > COALESCE((SELECT SUM(amount) FROM payments WHERE payments.student_id = students.id),0)')
                    ->whereNotIn('stage', ['Closed'])),

            'admitted' => Tab::make('Admitted')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('stage', 'Admission Confirmed')),

            'closed' => Tab::make('Closed')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('stage', 'Closed')),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'active';
    }
}
