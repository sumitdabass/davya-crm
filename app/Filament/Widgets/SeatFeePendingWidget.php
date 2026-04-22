<?php

namespace App\Filament\Widgets;

use App\Models\RoundHistory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SeatFeePendingWidget extends TableWidget
{
    protected static ?string $heading = 'Seat Fee Pending';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return self::baseQuery()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::baseQuery()->with('student'))
            ->columns([
                TextColumn::make('student.name')->label('Student')->searchable(),
                TextColumn::make('student.phone')->label('Phone'),
                TextColumn::make('round_name')->badge(),
                TextColumn::make('allotted_college')->label('College'),
                TextColumn::make('seat_fee_amount')->money('INR')->label('Fee'),
                TextColumn::make('created_at')->since()->label('Pending since'),
            ])
            ->paginated([10, 25, 50])
            ->defaultSort('created_at', 'asc');
    }

    private static function baseQuery(): Builder
    {
        $user = auth()->user();
        return RoundHistory::query()
            ->seatFeePending()
            ->whereHas('student', fn (Builder $q) => $q->visibleTo($user));
    }
}
