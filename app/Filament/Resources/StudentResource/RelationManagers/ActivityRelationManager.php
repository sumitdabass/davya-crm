<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityRelationManager extends RelationManager
{
    // The Student model doesn't expose a formal relation for activity_log, so we
    // short-circuit with a custom modifyQueryUsing below. The relationship name
    // here is a placeholder; table()->query() is the source of truth.
    protected static string $relationship = 'roundHistory';

    protected static ?string $title = 'Timeline';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Activity::query()
                ->where('subject_type', 'App\\Models\\Student')
                ->where('subject_id', $this->ownerRecord->id)
                ->latest())
            ->heading('Timeline')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('causer.name')->label('Who')->badge()->color('gray'),
                TextColumn::make('description')->label('What')->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
