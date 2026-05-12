<?php

namespace App\Filament\Widgets;

use App\Models\Student;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class StuckLeadsWidget extends TableWidget
{
    protected static ?string $heading = 'Stuck Leads (14+ days)';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Student::query()
            ->stuck()
            ->visibleTo(auth()->user())
            ->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Student::query()
                ->stuck()
                ->visibleTo(auth()->user())
                ->with('owner'))
            ->recordUrl(fn (Student $record): string => \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->searchable()
                    ->formatStateUsing(fn ($state, $record) => \App\Support\Aging::dotHtml($record->updated_at).e($state))
                    ->html(),
                TextColumn::make('phone'),
                TextColumn::make('stage')->badge(),
                TextColumn::make('owner.name')->label('Owner')->searchable(),
                TextColumn::make('updated_at')->since()->label('Last update')
                    ->tooltip(fn ($record) => $record->updated_at?->format('d M Y, H:i')),
            ])
            ->paginated([10, 25, 50])
            ->defaultSort('updated_at', 'asc');
    }
}
