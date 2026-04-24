<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Filament\Resources\StudentResource\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\RoundHistoryRelationManager;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use App\StudentFields\DynamicTableColumns;
use App\StudentFields\FieldRenderer;
use Filament\Notifications\Notification;
use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\User;
use Filament\Forms\Components\Section;
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

    // Canonical list lives in the pipelines/stages DB tables. Use PipelineConfig::stageNames().
    /** @return array<string,string> name => name (for Filament Select options). */
    private static function stageOptions(): array
    {
        return collect(app(PipelineConfig::class)->stageNames())
            ->mapWithKeys(fn (string $n) => [$n => $n])
            ->all();
    }

    /**
     * Read Select options for a built-in field from its StudentField record.
     * Falls back to the provided defaults if the record is missing or has no options.
     *
     * @param  array<int,string>  $fallback
     * @return array<string,string>
     */
    private static function optionsFor(string $key, array $fallback): array
    {
        $saved = StudentField::where('key', $key)->value('options');
        $list = (is_array($saved) && !empty($saved)) ? $saved : $fallback;
        return collect($list)->mapWithKeys(function ($v) {
            if (is_array($v) && isset($v['value'])) {
                return [$v['value'] => $v['label'] ?? $v['value']];
            }
            return [$v => $v];
        })->all();
    }

    public static function form(Form $form): Form
    {
        $baseSchema = [
            Tabs::make('student_form')
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make('Identity')
                        ->icon('heroicon-o-identification')
                        ->schema(array_merge([
                            TextInput::make('phone')->required()->unique(ignoreRecord: true)->tel(),
                            TextInput::make('name'),
                            TextInput::make('father_name'),
                            TextInput::make('phone_2')->tel()->label('Alternate phone'),
                            TextInput::make('email')->email()->maxLength(120),
                        ], self::customFieldsForSection('Identity')))->columns(2),

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

                            Select::make('stage')->options(fn () => self::stageOptions())->required()->default('Lead Captured')
                                ->live()
                                ->afterStateUpdated(function ($state, $record, $set) {
                                    if (! $record) {
                                        return;
                                    }
                                    $target = app(PipelineConfig::class)->stageByName($state);
                                    if (! $target) {
                                        Notification::make()->danger()->title('Stage change blocked')->body("Unknown stage: $state")->send();
                                        $set('stage', $record->getOriginal('stage'));
                                        return;
                                    }
                                    // Engine reads $record->stage_id as the "from" stage — don't mutate until after hard-check.
                                    $out = app(StageTransitionEngine::class)->forStageChange($record, $target->id);
                                    foreach ($out['hard'] as $err) {
                                        Notification::make()->danger()->title('Stage change blocked')->body($err)->send();
                                        $set('stage', $record->getOriginal('stage'));
                                        return;
                                    }
                                    $record->stage_id = $target->id;
                                    foreach ($out['soft'] as $warn) {
                                        Notification::make()->warning()->title('Stage changed — incomplete')->body($warn)->send();
                                    }
                                }),
                            Select::make('student_response')->options(fn () => self::optionsFor('student_response', ['Ready','Not Interested','Needs Time'])),
                            ...self::customFieldsForSection('Source & Stage'),
                        ])->columns(2),

                    Tabs\Tab::make('Academic')
                        ->icon('heroicon-o-academic-cap')
                        ->schema([
                            TextInput::make('exam_appeared'),
                            TextInput::make('twelfth_marks'),
                            TextInput::make('rank')->maxLength(40),
                            Select::make('category')->options(fn () => self::optionsFor('category', ['Delhi','Outside'])),
                            TextInput::make('state')->maxLength(40),
                            TextInput::make('course')->columnSpan(3),
                            TextInput::make('preference_r1')->label('1st choice')->required()->maxLength(120),
                            TextInput::make('preference_r2')->label('2nd choice (optional)')->maxLength(120),
                            TextInput::make('preference_r3')->label('3rd choice (optional)')->maxLength(120),
                            ...self::customFieldsForSection('Academic'),
                        ])->columns(3),

                    Tabs\Tab::make('Deal')
                        ->icon('heroicon-o-banknotes')
                        ->schema(array_merge([
                            TextInput::make('deal_amount')->numeric()->prefix('₹'),
                            Select::make('plan')->options(fn () => self::optionsFor('plan', ['Online','Offline','All'])),
                        ], self::customFieldsForSection('Deal')))->columns(2),

                    Tabs\Tab::make('Counselling')
                        ->icon('heroicon-o-key')
                        ->schema(array_merge([
                            Toggle::make('is_ipu_registered'),
                            TextInput::make('ipu_user_id'),
                            TextInput::make('ipu_login_code')
                                ->label('IPU login code')
                                ->maxLength(60)
                                ->helperText('Shared with the student during counselling.'),
                            TextInput::make('current_round'),
                            Toggle::make('seat_fee_due')->disabled(),
                        ], self::customFieldsForSection('Counselling')))->columns(2),

                    Tabs\Tab::make('History')
                        ->icon('heroicon-o-clock')
                        ->schema(array_merge([
                            \Filament\Forms\Components\Placeholder::make('activity_hint')
                                ->content('Notes and activity are shown in the tabs below the form.')
                                ->label(''),
                        ], self::customFieldsForSection('History'))),

                    Tabs\Tab::make('Closure')
                        ->icon('heroicon-o-x-circle')
                        ->badge(fn ($record) => $record?->stage === 'Closed' ? 'Closed' : null)
                        ->badgeColor('danger')
                        ->schema(array_merge([
                            Select::make('close_reason')->options(fn () => self::optionsFor('close_reason', ['Not Interested','Backed Out — Forfeit','Backed Out — Partial Refund','Completed','Other'])),
                            TextInput::make('refund_amount')->numeric()->prefix('₹'),
                            Textarea::make('re_entry_reason')->rows(2),
                            Textarea::make('description')->rows(3)->label('Description / freeform notes'),
                            Textarea::make('extra_notes')->rows(3)->label('Extra notes'),
                        ], self::customFieldsForSection('Closure')))->columns(2),
                ])
                ->persistTabInQueryString(),
        ];

        return $form->schema(array_merge($baseSchema, self::dynamicSections()));
    }

    /**
     * Custom (non-built-in) fields for a given section name, rendered as form components.
     * Injected into the matching Tab's schema so custom "Deal" fields land inside the Deal tab.
     */
    protected static function customFieldsForSection(string $sectionName): array
    {
        $section = StudentFieldSection::where('name', $sectionName)->first();
        if (!$section) return [];
        return StudentField::active()->custom()
            ->where('section_id', $section->id)
            ->orderBy('position')
            ->get()
            ->map(fn ($f) => (new FieldRenderer())->render($f))
            ->all();
    }

    /**
     * Fallback Section components for any custom field whose section name does NOT
     * match one of the tabs above (e.g. admin created a "Custom Notes" section).
     *
     * @return array<int, \Filament\Forms\Components\Section>
     */
    protected static function dynamicSections(): array
    {
        $tabNames = ['Identity','Source & Stage','Academic','Deal','Counselling','History','Closure'];
        return StudentFieldSection::orderBy('position')->get()
            ->reject(fn ($s) => in_array($s->name, $tabNames, true))
            ->map(function ($section) {
                $fields = StudentField::active()->custom()
                    ->where('section_id', $section->id)
                    ->orderBy('position')
                    ->get();
                if ($fields->isEmpty()) return null;

                return Section::make($section->name)
                    ->schema($fields->map(fn ($f) => (new FieldRenderer())->render($f))->all())
                    ->collapsed(false);
            })->filter()->values()->all();
    }

    public static function table(Table $table): Table
    {
        $baseColumns = [
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
        ];

        return $table
            ->persistFiltersInSession()
            ->columns(array_merge($baseColumns, (new DynamicTableColumns())->build()))
            ->filters([
                SelectFilter::make('owner_id')->relationship('owner', 'name'),
                SelectFilter::make('stage')->options(fn () => self::stageOptions()),
                SelectFilter::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
                Tables\Filters\Filter::make('stuck')
                    ->label('Stuck leads (14+ days)')
                    ->query(fn ($query) => $query
                        ->where('updated_at', '<', now()->subDays(14))
                        ->whereNotIn('stage', ['Admission Confirmed', 'Closed'])),
                Tables\Filters\Filter::make('seat_fee_pending')
                    ->label('Seat fee pending')
                    ->query(fn ($query) => $query->whereHas('roundHistory', fn ($q) => $q
                        ->where('outcome', 'Allotted — Fee Pending')
                        ->where('seat_fee_paid', false))),
                Tables\Filters\Filter::make('re_entry')
                    ->label('Re-entry candidates')
                    ->query(fn ($query) => $query->whereIn('id', \App\Models\RoundHistory::reEntryCandidates()->pluck('student_id'))),
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
