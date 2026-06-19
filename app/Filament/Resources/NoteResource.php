<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoteResource\Pages;
use App\Models\Note;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Notes';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Note::class) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')
                ->required()
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('raw_input')
                ->label('Raw Slack input')
                ->disabled()
                ->dehydrated(false)
                ->columnSpanFull()
                ->visible(fn ($record) => $record?->slack_message_id !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_id')
                    ->label('ID')
                    ->sortable(['id']),
                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (Note $r) => $r->slack_message_id ? 'Slack' : 'Manual')
                    ->color(fn (string $state) => $state === 'Slack' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('body')
                    ->limit(80)
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->options(['slack' => 'Slack', 'manual' => 'Manual'])
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? null) === 'slack') {
                            $query->whereNotNull('slack_message_id');
                        } elseif (($data['value'] ?? null) === 'manual') {
                            $query->whereNull('slack_message_id');
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotes::route('/'),
            'create' => Pages\CreateNote::route('/create'),
            'edit' => Pages\EditNote::route('/{record}/edit'),
        ];
    }
}
