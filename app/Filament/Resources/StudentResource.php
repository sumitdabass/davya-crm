<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Models\Student;
use App\Models\User;
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
            Section::make('Identity')->schema([
                TextInput::make('phone')->required()->unique(ignoreRecord: true)->tel(),
                TextInput::make('name')->required(),
                TextInput::make('father_name'),
                TextInput::make('phone_2')->tel(),
            ])->columns(2),

            Section::make('Source & Owner')->schema([
                Select::make('owner_id')->relationship('owner', 'name')->required()->searchable(),
                Select::make('referrer_id')->relationship('referrer', 'name')->required()->searchable(),
                Select::make('lead_source')
                    ->options(fn () => User::pluck('name', 'name')->toArray() + ['Other' => 'Other'])
                    ->required(),
            ])->columns(3),

            Section::make('Stage & Response')->schema([
                Select::make('stage')->options(self::STAGES)->required()->default('Lead Captured'),
                Select::make('student_response')->options([
                    'Ready' => 'Ready',
                    'Not Interested' => 'Not Interested',
                    'Needs Time' => 'Needs Time',
                ]),
            ])->columns(2),

            Section::make('Academic')->schema([
                TextInput::make('exam_appeared'),
                TextInput::make('twelfth_marks'),
                Select::make('category')->options(['Delhi' => 'Delhi', 'Outside' => 'Outside']),
            ])->columns(3),

            Section::make('Preferences')->schema([
                TextInput::make('course'),
                TextInput::make('preference_r1')->label('R1'),
                TextInput::make('preference_r2')->label('R2'),
                TextInput::make('preference_r3')->label('R3'),
            ])->columns(4),

            Section::make('Deal')->schema([
                TextInput::make('deal_amount')->numeric()->prefix('₹'),
                Select::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
            ])->columns(2),

            Section::make('Counselling')->schema([
                Toggle::make('is_ipu_registered'),
                TextInput::make('ipu_user_id'),
                TextInput::make('ipu_password')
                    ->password()
                    ->helperText('Stored encrypted. Revealing is logged to activity_log.'),
                TextInput::make('current_round'),
                Toggle::make('seat_fee_due')->disabled(),
            ])->columns(2),

            Section::make('Final')->schema([
                TextInput::make('final_college'),
                TextInput::make('final_course'),
                DatePicker::make('admission_date'),
            ])->columns(3),

            Section::make('Logistics')->schema([
                DateTimePicker::make('meeting_date'),
                TextInput::make('meeting_location'),
                Toggle::make('address_sent'),
                Toggle::make('office_visit'),
            ])->columns(2),

            Section::make('Closure')->schema([
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

            Section::make('Notes')->schema([
                Textarea::make('description')->rows(3),
                Textarea::make('extra_notes')->rows(3),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phone')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('owner.name')->label('Owner'),
                TextColumn::make('stage')->badge(),
                TextColumn::make('deal_amount')->money('INR'),
                TextColumn::make('total_received')->money('INR')->label('Received'),
                TextColumn::make('pending_amount')->money('INR')->label('Pending'),
                TextColumn::make('updated_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('owner_id')->relationship('owner', 'name'),
                SelectFilter::make('stage')->options(self::STAGES),
                SelectFilter::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
        return [];
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
