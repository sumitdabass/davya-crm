<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Expenses';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Expense::class) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->required()
                ->prefix('₹'),
            Forms\Components\TextInput::make('category')
                ->maxLength(60),
            Forms\Components\Textarea::make('description')
                ->rows(2),
            Forms\Components\DateTimePicker::make('paid_at')
                ->required()
                ->native(false)
                ->default(now()),
            Forms\Components\Textarea::make('raw_input')
                ->label('Raw Slack input')
                ->disabled()
                ->dehydrated(false)
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
                    ->state(fn (Expense $r) => $r->slack_message_id ? 'Slack' : 'Manual')
                    ->color(fn (string $state) => $state === 'Slack' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state) => \App\Support\MoneyFormat::asInlineHtml((float) $state))
                    ->html()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('paid_at')
                    ->since()
                    ->tooltip(fn ($record) => $record->paid_at?->format('d M Y, H:i'))
                    ->sortable(),
            ])
            ->defaultSort('paid_at', 'desc')
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
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
