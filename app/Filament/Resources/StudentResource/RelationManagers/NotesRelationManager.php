<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Notes';

    protected static ?string $icon = 'heroicon-o-chat-bubble-left-right';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')
                ->label('Note')
                ->required()
                ->rows(3)
                ->placeholder('Add a quick note — anyone on the team can see this.')
                ->columnSpanFull(),
            Forms\Components\Hidden::make('author_id')
                ->default(fn () => auth()->id()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('author.name')
                    ->label('By')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('body')
                    ->wrap()
                    ->limit(200),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add note')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Add note'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => auth()->user()?->hasRole('admin') || $record->author_id === auth()->id()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => auth()->user()?->hasRole('admin') || $record->author_id === auth()->id()),
            ])
            ->paginated([5, 10, 25]);
    }
}
