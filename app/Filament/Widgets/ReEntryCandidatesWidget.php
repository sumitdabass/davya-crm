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

    public static function canView(): bool
    {
        return self::baseQuery()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::baseQuery()->with('student.owner'))
            ->recordUrl(fn (RoundHistory $record): ?string => $record->student_id
                ? \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $record->student_id])
                : null)
            ->columns([
                TextColumn::make('student.name')->label('Student')->searchable()
                    ->formatStateUsing(fn ($state, $record) => \App\Support\Aging::dotHtml($record->student?->updated_at).e($state ?? ''))
                    ->html(),
                TextColumn::make('student.phone')->label('Phone'),
                TextColumn::make('round_name')->badge()->label('Last round'),
                TextColumn::make('allotted_college')->label('Was allotted'),
                TextColumn::make('student.owner.name')->label('Owner'),
                TextColumn::make('created_at')->since()->label('Kicked out')
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i')),
            ])
            ->paginated([10, 25, 50])
            ->defaultSort('created_at', 'desc');
    }

    private static function baseQuery(): Builder
    {
        $user = auth()->user();
        return RoundHistory::query()
            ->reEntryCandidates()
            ->whereHas('student', fn (Builder $q) => $q->visibleTo($user));
    }
}
