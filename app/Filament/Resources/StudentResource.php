<?php

namespace App\Filament\Resources;

use App\Enums\PipelineStage;
use App\Filament\Resources\StudentResource\Pages;
use App\Filament\Resources\StudentResource\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\RoundHistoryRelationManager;
use App\Services\StageTransitionValidator;
use Filament\Notifications\Notification;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'phone_2', 'email', 'father_name', 'ipu_user_id'];
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return $record->name.' — '.$record->phone;
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'Stage' => $record->stage,
            'Owner' => $record->owner?->name ?? '—',
            'Deal'  => $record->deal_amount ? '₹'.number_format($record->deal_amount, 0, '.', ',') : '—',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        // Respect the same visibility rules as the list view.
        return self::getEloquentQuery();
    }

    // Canonical list lives in App\Enums\PipelineStage. Use PipelineStage::options().

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('student_form')
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make('Identity')
                        ->icon('heroicon-o-identification')
                        ->schema([
                            TextInput::make('phone')->required()->unique(ignoreRecord: true)->tel(),
                            TextInput::make('name'),
                            TextInput::make('father_name'),
                            TextInput::make('phone_2')->tel()->label('Alternate phone'),
                            TextInput::make('email')->email()->maxLength(120),
                        ])->columns(2),

                    Tabs\Tab::make('Source & Stage')
                        ->icon('heroicon-o-user-group')
                        ->schema([
                            Select::make('owner_id')
                                ->label('Owner')
                                ->relationship(
                                    name: 'owner',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn ($query) =>
                                        $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'head'])),
                                )
                                ->required()
                                ->searchable(),
                            Select::make('lead_source')
                                ->label('Lead Source')
                                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'name'))
                                ->required()
                                ->searchable(),
                            TextInput::make('referrer_name')->label('Referrer name')->maxLength(120),

                            Select::make('stage')->options(PipelineStage::options())->required()->default(PipelineStage::LeadCaptured->value)
                                ->live()
                                ->afterStateUpdated(function ($state, $record, $set) {
                                    if (! $record) {
                                        return;
                                    }
                                    $out = (new StageTransitionValidator)->forStageChange($record, $state);
                                    foreach ($out['hard'] as $err) {
                                        Notification::make()->danger()->title('Stage change blocked')->body($err)->send();
                                        $set('stage', $record->getOriginal('stage'));
                                        return;
                                    }
                                    foreach ($out['soft'] as $warn) {
                                        Notification::make()->warning()->title('Stage changed — incomplete')->body($warn)->send();
                                    }
                                }),
                            Select::make('student_response')->options([
                                'Ready' => 'Ready',
                                'Not Interested' => 'Not Interested',
                                'Needs Time' => 'Needs Time',
                            ]),
                        ])->columns(2),

                    Tabs\Tab::make('Academic')
                        ->icon('heroicon-o-academic-cap')
                        ->schema([
                            TextInput::make('exam_appeared'),
                            TextInput::make('twelfth_marks'),
                            TextInput::make('rank')->maxLength(40),
                            Select::make('category')->options(['Delhi' => 'Delhi', 'Outside' => 'Outside']),
                            TextInput::make('state')->maxLength(40),
                            TextInput::make('course')->columnSpan(3),
                            TextInput::make('preference_r1')->label('1st choice')->required()->maxLength(120),
                            TextInput::make('preference_r2')->label('2nd choice (optional)')->maxLength(120),
                            TextInput::make('preference_r3')->label('3rd choice (optional)')->maxLength(120),
                        ])->columns(3),

                    Tabs\Tab::make('Deal')
                        ->icon('heroicon-o-banknotes')
                        ->schema([
                            TextInput::make('deal_amount')->numeric()->prefix('₹'),
                            Select::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
                        ])->columns(2),

                    Tabs\Tab::make('Counselling')
                        ->icon('heroicon-o-key')
                        ->schema([
                            Toggle::make('is_ipu_registered'),
                            TextInput::make('ipu_user_id'),
                            TextInput::make('ipu_login_code')
                                ->label('IPU login code')
                                ->maxLength(60)
                                ->helperText('Shared with the student during counselling.'),
                            TextInput::make('current_round'),
                            Toggle::make('seat_fee_due')->disabled(),
                        ])->columns(2),

                    Tabs\Tab::make('History')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            \Filament\Forms\Components\Placeholder::make('activity_hint')
                                ->content('Notes and activity are shown in the tabs below the form.')
                                ->label(''),
                        ]),

                    Tabs\Tab::make('Closure')
                        ->icon('heroicon-o-x-circle')
                        ->badge(fn ($record) => $record?->stage === 'Closed' ? 'Closed' : null)
                        ->badgeColor('danger')
                        ->schema([
                            Select::make('close_reason')->options([
                                'Not Interested' => 'Not Interested',
                                'Backed Out — Forfeit' => 'Backed Out — Forfeit',
                                'Backed Out — Partial Refund' => 'Backed Out — Partial Refund',
                                'Completed' => 'Completed',
                                'Other' => 'Other',
                            ]),
                            TextInput::make('refund_amount')->numeric()->prefix('₹'),
                            Textarea::make('re_entry_reason')->rows(2),
                            Textarea::make('description')->rows(3)->label('Description / freeform notes'),
                            Textarea::make('extra_notes')->rows(3)->label('Extra notes'),
                        ])->columns(2),
                ])
                ->persistTabInQueryString(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium')->sortable()
                    ->description(fn ($record) => $record->phone),
                TextColumn::make('owner.name')->label('Owner')->badge()->color('gray'),
                TextColumn::make('stage')->badge()->color(fn ($state) => match ($state) {
                    'Lead Captured'           => 'gray',
                    'Meeting Scheduled'       => 'info',
                    'Meeting Done'            => 'info',
                    'Onboarded'               => 'warning',
                    'University Registration' => 'warning',
                    'Counselling In Progress' => 'primary',
                    'Seat Allotted'           => 'primary',
                    'Full Payment Received'   => 'success',
                    'Admission Confirmed'     => 'success',
                    'Closed'                  => 'danger',
                    default                   => 'gray',
                }),
                TextColumn::make('deal_amount')->money('INR')->sortable()->default(0),
                TextColumn::make('total_received')->money('INR')->label('Received')
                    ->color('success'),
                TextColumn::make('pending_amount')->money('INR')->label('Pending')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rank')->toggleable(isToggledHiddenByDefault: true)->sortable(),
                TextColumn::make('state')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('updated_at')->since()->label('Last update')->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('owner_id')->relationship('owner', 'name'),
                SelectFilter::make('stage')->options(PipelineStage::options()),
                SelectFilter::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('reassign_owner')
                        ->label('Reassign owner')
                        ->icon('heroicon-m-user')
                        ->color('primary')
                        ->visible(fn () => auth()->user()?->hasRole(['admin', 'head']))
                        ->form([
                            Select::make('owner_id')
                                ->label('New owner')
                                ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records, array $data): void {
                            $newOwnerId = (int) $data['owner_id'];
                            $caller = auth()->user();
                            $touched = 0;

                            foreach ($records as $student) {
                                // Members cannot transfer (matches StudentPolicy::transfer).
                                if (! $caller->hasRole(['admin', 'head'])) {
                                    continue;
                                }
                                // Head can only reassign students they can see.
                                if ($caller->hasRole('head') && ! $caller->hasRole('admin')) {
                                    $teamIds = User::where('team_head_id', $caller->id)->pluck('id')->all();
                                    $teamIds[] = $caller->id;
                                    if (! in_array($student->owner_id, $teamIds, true)) {
                                        continue;
                                    }
                                }
                                $student->owner_id = $newOwnerId;
                                $student->save();
                                $touched++;
                            }

                            \Filament\Notifications\Notification::make()
                                ->title("Reassigned {$touched} student".($touched === 1 ? '' : 's'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
            RoundHistoryRelationManager::class,
            NotesRelationManager::class,
            MeetingsRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
