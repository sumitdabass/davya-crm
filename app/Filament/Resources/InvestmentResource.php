<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvestmentResource\Pages;
use App\Models\Investment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvestmentResource extends Resource
{
    protected static ?string $model = Investment::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Investments';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('asset_name')
                ->required()
                ->maxLength(80),
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->required()
                ->prefix('₹'),
            Forms\Components\Select::make('direction')
                ->options(['in' => 'In (buy / add)', 'out' => 'Out (sell / withdraw)'])
                ->required(),
            Forms\Components\DateTimePicker::make('transacted_at')
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
                    ->state(fn (Investment $r) => $r->slack_message_id ? 'Slack' : 'Manual')
                    ->color(fn (string $state) => $state === 'Slack' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('asset_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('amount')
                    ->money('INR', locale: 'en_IN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('transacted_at')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('transacted_at', 'desc')
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
                Tables\Filters\SelectFilter::make('direction')
                    ->options(['in' => 'In', 'out' => 'Out']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvestments::route('/'),
            'create' => Pages\CreateInvestment::route('/create'),
            'edit' => Pages\EditInvestment::route('/{record}/edit'),
        ];
    }
}
