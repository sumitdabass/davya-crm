<?php

namespace App\Filament\Widgets;

use App\Models\RoundHistory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ReEntryCandidatesWidget extends TableWidget
{
    protected static ?string $heading = 'Re-entry Candidates';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RoundHistory::query()->reEntryCandidates()->with('student.owner'))
            ->columns([
                TextColumn::make('student.name')->label('Student')->searchable(),
                TextColumn::make('student.phone')->label('Phone'),
                TextColumn::make('round_name')->badge()->label('Last round'),
                TextColumn::make('allotted_college')->label('Was allotted'),
                TextColumn::make('student.owner.name')->label('Owner'),
                TextColumn::make('created_at')->since()->label('Kicked out'),
            ])
            ->paginated([10, 25, 50])
            ->defaultSort('created_at', 'desc');
    }
}
