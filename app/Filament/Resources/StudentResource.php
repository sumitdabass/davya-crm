<?php

namespace App\Filament\Resources;

use App\Actions\RevealIpuPassword;
use App\Filament\Resources\StudentResource\Pages;
use App\Filament\Resources\StudentResource\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\RoundHistoryRelationManager;
use App\Services\StageTransitionValidator;
use Filament\Notifications\Notification;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
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
        return ['name', 'phone', 'phone_2', 'father_name', 'ipu_user_id', 'final_college'];
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

    protected const STAGES = [
        'Lead Captured' => 'Lead Captured',
        'Meeting Scheduled' => 'Meeting Scheduled',
        'Meeting Done' => 'Meeting Done',
        'Onboarded' => 'Onboarded',
        'University Registration' => 'University Registration',
        'Counselling In Progress' => 'Counselling In Progress',
        'Seat Allotted' => 'Seat Allotted',
        'Full Payment Received' => 'Full Payment Received',
        'Admission Confirmed' => 'Admission Confirmed',
        'Closed' => 'Closed',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identity')
                ->description('Primary contact details used to reach the student.')
                ->icon('heroicon-o-identification')
                ->schema([
                    TextInput::make('phone')->required()->unique(ignoreRecord: true)->tel(),
                    TextInput::make('name')->required(),
                    TextInput::make('father_name'),
                    TextInput::make('phone_2')->tel()->label('Alternate phone'),
                ])->columns(2),

            Section::make('Source & Owner')
                ->description('Who brought the lead and who handles it now.')
                ->icon('heroicon-o-user-group')
                ->schema([
                    Select::make('owner_id')->relationship('owner', 'name')->required()->searchable(),
                    Select::make('referrer_id')->relationship('referrer', 'name')->required()->searchable(),
                    Select::make('lead_source')
                        ->options(fn () => User::pluck('name', 'name')->toArray() + ['Other' => 'Other'])
                        ->required(),
                ])->columns(3),

            Section::make('Stage & Response')
                ->description('Where this student is in the pipeline.')
                ->icon('heroicon-o-flag')
                ->schema([
                    Select::make('stage')->options(self::STAGES)->required()->default('Lead Captured')
                        ->live()
                        ->afterStateUpdated(function ($state, $record) {
                            if (! $record) {
                                return;
                            }
                            foreach ((new StageTransitionValidator)->forStageChange($record, $state) as $err) {
                                Notification::make()->warning()->title($err)->send();
                            }
                        }),
                    Select::make('student_response')->options([
                        'Ready' => 'Ready',
                        'Not Interested' => 'Not Interested',
                        'Needs Time' => 'Needs Time',
                    ]),
                ])->columns(2),

            Section::make('Academic')
                ->description('Board marks, exam, and category.')
                ->icon('heroicon-o-academic-cap')
                ->collapsible()
                ->schema([
                    TextInput::make('exam_appeared'),
                    TextInput::make('twelfth_marks'),
                    Select::make('category')->options(['Delhi' => 'Delhi', 'Outside' => 'Outside']),
                    TextInput::make('course')->columnSpan(3),
                ])->columns(3),

            Section::make('College Preference')
                ->description('Three college choices we offer the student. First choice is mandatory; second and third are optional.')
                ->icon('heroicon-o-building-library')
                ->schema([
                    TextInput::make('preference_r1')
                        ->label('1st choice')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('preference_r2')
                        ->label('2nd choice (optional)')
                        ->maxLength(120),
                    TextInput::make('preference_r3')
                        ->label('3rd choice (optional)')
                        ->maxLength(120),
                ])->columns(3),

            Section::make('Deal')
                ->description('Package chosen and total deal amount.')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    TextInput::make('deal_amount')->numeric()->prefix('₹'),
                    Select::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
                ])->columns(2),

            Section::make('Counselling')
                ->description('IPU portal credentials and current round.')
                ->icon('heroicon-o-key')
                ->collapsible()
                ->schema([
                    Toggle::make('is_ipu_registered'),
                    TextInput::make('ipu_user_id'),
                    TextInput::make('ipu_password')
                        ->password()
                        ->suffixAction(
                            Action::make('reveal')
                                ->icon('heroicon-o-eye')
                                ->visible(fn ($record) => $record !== null)
                                ->action(function ($record, $set) {
                                    $revealed = (new RevealIpuPassword)($record);
                                    $set('ipu_password', $revealed);
                                }),
                        )
                        ->helperText('Stored encrypted. Revealing is logged to activity_log.'),
                    TextInput::make('current_round'),
                    Toggle::make('seat_fee_due')->disabled(),
                ])->columns(2),

            Section::make('Final allotment')
                ->description('Locked-in college and admission date.')
                ->icon('heroicon-o-check-badge')
                ->collapsible()
                ->schema([
                    TextInput::make('final_college'),
                    TextInput::make('final_course'),
                    DatePicker::make('admission_date'),
                ])->columns(3),

            Section::make('Logistics')
                ->description('Office visit and meeting tracking.')
                ->icon('heroicon-o-map-pin')
                ->collapsible()
                ->collapsed()
                ->schema([
                    DateTimePicker::make('meeting_date'),
                    TextInput::make('meeting_location'),
                    Toggle::make('address_sent'),
                    Toggle::make('office_visit'),
                ])->columns(2),

            Section::make('Closure')
                ->description('Only fill when closing or re-opening a student.')
                ->icon('heroicon-o-x-circle')
                ->collapsible()
                ->collapsed(fn ($record) => ! in_array($record?->stage, ['Closed'], true))
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
                ])->columns(2),

            Section::make('Freeform notes')
                ->description('For long-form context. The Notes panel below is better for ongoing updates.')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Textarea::make('description')->rows(3)->label('Description'),
                    Textarea::make('extra_notes')->rows(3)->label('Extra notes'),
                ])->columns(1),
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
                TextColumn::make('deal_amount')->money('INR')->sortable(),
                TextColumn::make('total_received')->money('INR')->label('Received')
                    ->color('success'),
                TextColumn::make('pending_amount')->money('INR')->label('Pending')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('updated_at')->since()->label('Last update')->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('owner_id')->relationship('owner', 'name'),
                SelectFilter::make('stage')->options(self::STAGES),
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
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        if ($user->hasRole('admin')) {
            return $query;
        }
        if ($user->hasRole('head')) {
            $teamIds = User::where('team_head_id', $user->id)->pluck('id')->toArray();
            $teamIds[] = $user->id;
            return $query->whereIn('owner_id', $teamIds);
        }
        return $query->where('owner_id', $user->id);
    }

    public static function getRelations(): array
    {
        return [
            NotesRelationManager::class,
            PaymentsRelationManager::class,
            RoundHistoryRelationManager::class,
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
