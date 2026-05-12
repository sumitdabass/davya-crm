<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Models\Meeting;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeetingsRelationManager extends RelationManager
{
    protected static string $relationship = 'meetings';

    protected static ?string $title = 'Meetings';

    protected static ?string $icon = 'heroicon-o-calendar';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('owner_id')
                ->label('Owner')
                ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all())
                ->default(fn () => auth()->id())
                ->required(),
            Forms\Components\DateTimePicker::make('scheduled_at')
                ->label('Scheduled at')
                ->required()
                ->native(false)
                ->default(fn () => now()->addDay()),
            Forms\Components\Select::make('mode')
                ->options([
                    'in_person' => 'In person',
                    'phone'     => 'Phone',
                    'video'     => 'Video',
                    'whatsapp'  => 'WhatsApp',
                ])
                ->default('in_person')
                ->required(),
            Forms\Components\Textarea::make('notes')
                ->label('Pre-meeting notes')
                ->rows(2),
            Forms\Components\Textarea::make('outcome_notes')
                ->label('Outcome notes (after meeting held)')
                ->rows(2)
                ->visible(fn ($record) => $record?->status === 'held'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('When')
                    ->since()
                    ->tooltip(fn ($record) => $record->scheduled_at?->format('d M Y, H:i'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('mode')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'scheduled'    => 'info',
                        'held'         => 'success',
                        'no_show'      => 'warning',
                        'rescheduled'  => 'gray',
                        'cancelled'    => 'danger',
                        default        => 'gray',
                    }),
                Tables\Columns\TextColumn::make('owner.name')->label('Owner'),
                Tables\Columns\TextColumn::make('notes')->limit(40),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['status'] = 'scheduled';
                        $data['created_by_id'] = auth()->id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('markHeld')
                    ->label('Mark held')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Meeting $r) => $r->status === 'scheduled')
                    ->action(fn (Meeting $r) => $r->update(['status' => 'held'])),
                Tables\Actions\Action::make('markNoShow')
                    ->label('No-show')
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->visible(fn (Meeting $r) => $r->status === 'scheduled')
                    ->action(fn (Meeting $r) => $r->update(['status' => 'no_show'])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Meeting $r) => $r->status === 'scheduled')
                    ->form([
                        Forms\Components\DateTimePicker::make('new_scheduled_at')
                            ->label('New time')
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Meeting $r, array $data): void {
                        Meeting::create([
                            'student_id'          => $r->student_id,
                            'owner_id'            => $r->owner_id,
                            'scheduled_at'        => $data['new_scheduled_at'],
                            'mode'                => $r->mode,
                            'status'              => 'scheduled',
                            'rescheduled_from_id' => $r->id,
                            'created_by_id'       => auth()->id(),
                        ]);
                        $r->update(['status' => 'rescheduled']);
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    protected function getTableQuery(): ?Builder
    {
        $query = $this->getOwnerRecord()->meetings()->getQuery();
        if (auth()->check()) {
            $query = $query->visibleTo(auth()->user());
        }
        return $query;
    }
}
