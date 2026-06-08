<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Filament\Resources\StudentResource\Pages;
use App\Filament\Resources\StudentResource\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\RoundHistoryRelationManager;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use App\Services\PipelineSummary;
use App\StudentFields\DynamicTableColumns;
use App\StudentFields\FieldRenderer;
use App\Support\Aging;
use App\Support\MoneyFormat;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

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
            'Deal' => $record->deal_amount ? '₹'.number_format($record->deal_amount, 0, '.', ',') : '—',
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
        $list = (is_array($saved) && ! empty($saved)) ? $saved : $fallback;

        return collect($list)->mapWithKeys(function ($v) {
            if (is_array($v) && isset($v['value'])) {
                return [$v['value'] => $v['label'] ?? $v['value']];
            }

            return [$v => $v];
        })->all();
    }

    public static function form(Form $form): Form
    {
        $authUser = auth()->user();
        $isAdmin = $authUser?->hasRole('admin') ?? false;
        $isHead = $authUser?->hasRole('head') ?? false;

        $stageField = Select::make('stage')->options(fn () => self::stageOptions())->required()->default('Lead Captured')
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
            });

        $baseSchema = [
            // Stage lives at the top of the form, outside the tabs, so it's
            // visible (and editable) regardless of which tab the operator is on.
            Section::make('Stage')
                ->icon('heroicon-o-flag')
                ->schema([$stageField])
                ->columnSpanFull()
                ->compact(),

            Tabs::make('student_form')
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make('Identity')
                        ->icon('heroicon-o-identification')
                        ->schema(array_merge([
                            Select::make('owner_id')
                                ->label('Owner')
                                ->helperText($isAdmin ? null : 'Auto-assigned. Only admin can change owner.')
                                ->relationship(
                                    name: 'owner',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn ($query) => $query->where('is_active', true)->orderBy('name'),
                                )
                                ->default(fn () => auth()->id())
                                ->disabled(! $isAdmin)
                                ->dehydrated()
                                ->required()
                                ->searchable(),
                            Select::make('referrer_id')
                                ->label('Lead Owner')
                                ->helperText(
                                    $isAdmin
                                        ? null
                                        : ($isHead ? 'Head: editable once. After save, contact admin to change.' : 'Only admin or the head who captured the lead can edit.')
                                )
                                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->disabled(function ($record) use ($isAdmin, $isHead) {
                                    if ($isAdmin) {
                                        return false;
                                    }
                                    if (! $isHead) {
                                        return true;
                                    }

                                    // Head: lock if already saved AND locked_at is set.
                                    return $record !== null && $record->referrer_id_locked_at !== null;
                                })
                                ->dehydrated(),
                            Select::make('lead_source')
                                ->label('Lead Source')
                                ->options(fn () => self::optionsFor('lead_source', ['FB', 'Insta', 'Cold Calling', 'Google', 'Personal Ref', 'Other']))
                                ->required()
                                ->searchable(),
                            Select::make('student_response')->options(fn () => self::optionsFor('student_response', ['Ready', 'Not Interested', 'Needs Time'])),
                            TextInput::make('phone')->required()->unique(ignoreRecord: true)->tel(),
                            TextInput::make('name')->required(),
                            TextInput::make('father_name'),
                            TextInput::make('phone_2')->tel()->label('Alternate phone'),
                            TextInput::make('email')->email()->maxLength(120)->label('Email 1'),
                            TextInput::make('email_2')->email()->maxLength(120)->label('Email 2'),
                            Textarea::make('address')->rows(2)->columnSpanFull(),
                        ], self::customFieldsForSection('Source & Stage')))->columns(['default' => 1, 'md' => 2])
                        ->extraAttributes([
                            'class' => config('davyas.visual_v2') ? 'davya-section' : '',
                        ]),

                    Tabs\Tab::make('Academic')
                        ->icon('heroicon-o-academic-cap')
                        ->schema(array_merge([
                            TextInput::make('course'),
                            TextInput::make('university'),
                            TextInput::make('exam_appeared'),
                            TextInput::make('rank')->maxLength(40),
                            TextInput::make('twelfth_marks')->label('12th Marks %'),
                            Select::make('category')->options(fn () => self::optionsFor('category', ['Delhi', 'Outside'])),
                            TextInput::make('sub_category')->label('Sub Category')->maxLength(60),
                            TextInput::make('state')->maxLength(40),
                            TextInput::make('preference_r1')->label('1st choice')->required()->maxLength(120),
                            TextInput::make('preference_r2')->label('2nd choice (optional)')->maxLength(120),
                            TextInput::make('preference_r3')->label('3rd choice (optional)')->maxLength(120),
                            ...self::customFieldsForSection('Identity'),
                            ...self::customFieldsForSection('Academic'),
                        ]))->columns(['default' => 1, 'md' => 3])
                        ->extraAttributes([
                            'class' => config('davyas.visual_v2') ? 'davya-section' : '',
                        ]),

                    Tabs\Tab::make('Deal & Counselling')
                        ->icon('heroicon-o-banknotes')
                        ->schema(array_merge([
                            TextInput::make('deal_amount')->numeric()->prefix('₹'),
                            Repeater::make('payouts')
                                ->relationship()
                                ->label('Payouts (to college / other)')
                                ->columnSpanFull()
                                ->defaultItems(0)
                                ->addActionLabel('Add payout')
                                ->schema([
                                    Select::make('payee_type')
                                        ->options(['college' => 'College', 'other' => 'Other'])
                                        ->default('college')
                                        ->required(),
                                    TextInput::make('payee_name')
                                        ->label('Payee name')
                                        ->placeholder('College / party name')
                                        ->maxLength(120),
                                    TextInput::make('amount')->numeric()->prefix('₹')->required(),
                                    Select::make('status')
                                        ->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])
                                        ->default('to_pay')
                                        ->live()
                                        ->required(),
                                    DateTimePicker::make('paid_at')
                                        ->label('Paid on')
                                        ->visible(fn (Get $get) => $get('status') === 'paid'),
                                ])
                                ->columns(['default' => 1, 'md' => 2])
                                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                    $data['recorded_by_user_id'] = auth()->id();

                                    return $data;
                                })
                                ->live(),
                            Placeholder::make('expected_profit_preview')
                                ->label('Expected profit')
                                ->columnSpanFull()
                                ->content(function (Get $get): HtmlString {
                                    $deal = (float) ($get('deal_amount') ?? 0);
                                    $payouts = collect($get('payouts') ?? [])
                                        ->sum(fn ($row) => (float) ($row['amount'] ?? 0));
                                    $profit = $deal - $payouts;

                                    return new HtmlString(
                                        '₹'.number_format($deal, 0).' deal − ₹'.number_format($payouts, 0).' payouts = '
                                        .MoneyFormat::asInlineHtml($profit, $profit < 0, true)
                                    );
                                }),
                            Select::make('plan')->options(fn () => self::optionsFor('plan', ['Sitting', 'Counselling Online', 'Counselling Offline'])),
                            Select::make('registration_status')
                                ->label('IPU Registration Status')
                                ->options([
                                    'pending' => 'Registration pending',
                                    'registration_done' => 'Registration done',
                                    'fee_paid' => 'Fee payment done',
                                ])
                                ->default('pending')
                                ->required(),
                            Select::make('counselling_registration_status')
                                ->label('Counselling Registration Status')
                                ->options([
                                    'pending' => 'Registration pending',
                                    'registration_done' => 'Registration done',
                                    'fee_paid' => 'Fee payment done',
                                ])
                                ->default('pending')
                                ->required(),
                            TextInput::make('ipu_user_id')->label('IPU User ID'),
                            TextInput::make('ipu_login_code')
                                ->label('IPU Login Code')
                                ->maxLength(60)
                                ->helperText('Shared with the student during counselling.'),
                            TextInput::make('current_round'),
                            Select::make('seat_allotment_fee_status')
                                ->label('Seat Allotment Fee Status')
                                ->options([
                                    'not_allotted' => 'Seat not allotted till now',
                                    'allotted_fee_pending' => 'Seat allotted, fee not paid',
                                    'allotted_fee_paid' => 'Seat allotted, fee paid',
                                    'next_round' => 'Fee paid — processing next round',
                                ])
                                ->default('not_allotted')
                                ->required(),
                        ], self::customFieldsForSection('Deal'), self::customFieldsForSection('Counselling')))->columns(['default' => 1, 'md' => 2])
                        ->extraAttributes([
                            'class' => config('davyas.visual_v2') ? 'davya-section' : '',
                        ]),

                    Tabs\Tab::make('Account')
                        ->icon('heroicon-o-document-text')
                        ->badge(fn ($record) => $record?->stage === 'Closed' ? 'Closed' : null)
                        ->badgeColor('danger')
                        ->schema(array_merge([
                            // Payment + Note quick-add buttons. Both open the same forms used by
                            // the relation managers below — keeps a single source of truth for
                            // the Payment form (PaymentFormSchema).
                            Actions::make([
                                Action::make('addPayment')
                                    ->label('+ New Payment')
                                    ->color('success')
                                    ->icon('heroicon-o-banknotes')
                                    ->visible(fn ($record) => $record !== null)
                                    ->form(PaymentFormSchema::fields(inlineFirstPayment: false))
                                    ->action(function (array $data, $record): void {
                                        $data = PaymentFormSchema::resolveProofUpload($data);
                                        $data['student_id'] = $record->id;
                                        $data['recorded_by_user_id'] = auth()->id();
                                        Payment::create($data);
                                        Notification::make()->success()->title('Payment recorded')->send();
                                    })
                                    ->modalWidth('lg'),
                                Action::make('addNote')
                                    ->label('+ New Note')
                                    ->color('primary')
                                    ->icon('heroicon-o-pencil-square')
                                    ->visible(fn ($record) => $record !== null)
                                    ->form([
                                        Textarea::make('body')->label('Note')->rows(4)->required(),
                                    ])
                                    ->action(function (array $data, $record): void {
                                        StudentNote::create([
                                            'student_id' => $record->id,
                                            'author_id' => auth()->id(),
                                            'body' => $data['body'],
                                        ]);
                                        Notification::make()->success()->title('Note added')->send();
                                    })
                                    ->modalWidth('md'),
                            ])->columnSpanFull(),

                            // Closure — always visible.
                            Section::make('Closure')
                                ->description('Fill these only when wrapping up the student.')
                                ->schema(array_merge([
                                    Select::make('close_reason')->options(fn () => self::optionsFor('close_reason', ['Not Interested', 'Backed Out — Forfeit', 'Backed Out — Partial Refund', 'Completed', 'Other'])),
                                    TextInput::make('refund_amount')->numeric()->prefix('₹'),
                                    Textarea::make('re_entry_reason')->rows(2)->columnSpanFull(),
                                ], self::customFieldsForSection('Closure')))
                                ->columns(['default' => 1, 'md' => 2]),

                            // Inline summaries: recent payments, notes, timeline. Read-only —
                            // operators add via the action buttons above; the panels below the
                            // form are still there for table view + CSV export.
                            View::make('filament.forms.account-summary')
                                ->visible(fn ($record) => $record !== null)
                                ->columnSpanFull(),
                        ], self::customFieldsForSection('History')))
                        ->extraAttributes([
                            'class' => config('davyas.visual_v2') ? 'davya-section' : '',
                        ]),
                ])
                ->persistTabInQueryString(),

            Textarea::make('description')
                ->label('Quick notes')
                ->placeholder('Jot anything — visible on every tab, saved with the student.')
                ->rows(2)
                ->columnSpanFull(),
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
        if (! $section) {
            return [];
        }

        return StudentField::active()->custom()
            ->where('section_id', $section->id)
            ->orderBy('position')
            ->get()
            ->map(fn ($f) => (new FieldRenderer)->render($f))
            ->all();
    }

    /**
     * Fallback Section components for any custom field whose section name does NOT
     * match one of the tabs above (e.g. admin created a "Custom Notes" section).
     *
     * @return array<int, Section>
     */
    protected static function dynamicSections(): array
    {
        $tabNames = ['Identity', 'Source & Stage', 'Academic', 'Deal', 'Counselling', 'History', 'Closure'];

        return StudentFieldSection::orderBy('position')->get()
            ->reject(fn ($s) => in_array($s->name, $tabNames, true))
            ->map(function ($section) {
                $fields = StudentField::active()->custom()
                    ->where('section_id', $section->id)
                    ->orderBy('position')
                    ->get();
                if ($fields->isEmpty()) {
                    return null;
                }

                return Section::make($section->name)
                    ->schema($fields->map(fn ($f) => (new FieldRenderer)->render($f))->all())
                    ->collapsed(false);
            })->filter()->values()->all();
    }

    public static function table(Table $table): Table
    {
        $baseColumns = [
            TextColumn::make('name')->searchable()->weight('medium')->sortable()
                ->formatStateUsing(fn ($state, $record) => Aging::dotHtml($record->updated_at).e($state))
                ->html()
                ->description(fn ($record) => $record->phone),
            TextColumn::make('owner.name')->label('Owner')->badge()->color('gray'),
            TextColumn::make('stage')->badge()->color(fn ($state) => match ($state) {
                'Lead Captured' => 'gray',
                'Meeting Scheduled' => 'info',
                'Meeting Done' => 'info',
                'Onboarded' => 'warning',
                'University Registration' => 'warning',
                'Counselling In Progress' => 'primary',
                'Seat Allotted' => 'primary',
                'Full Payment Received' => 'success',
                'Admission Confirmed' => 'success',
                'Closed' => 'danger',
                default => 'gray',
            }),
            TextColumn::make('deal_amount')->label('Deal')->sortable()->default(0)
                ->formatStateUsing(fn ($state) => MoneyFormat::asInlineHtml((float) $state))->html(),
            TextColumn::make('total_received')->label('Received')->sortable()
                ->formatStateUsing(fn ($state) => $state > 0
                    ? '<span style="color:var(--success,#10B981);">'.MoneyFormat::asInlineHtml((float) $state).'</span>'
                    : MoneyFormat::asInlineHtml(0))->html(),
            TextColumn::make('pending_amount')->label('Pending')->sortable()
                ->formatStateUsing(fn ($state) => $state > 0
                    ? '<span style="color:var(--warning,#F59E0B);">'.MoneyFormat::asInlineHtml((float) $state).'</span>'
                    : MoneyFormat::asInlineHtml(0))->html(),
            TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('rank')->toggleable(isToggledHiddenByDefault: true)->sortable(),
            TextColumn::make('state')->toggleable(isToggledHiddenByDefault: true)->searchable(),
            TextColumn::make('updated_at')->since()->label('Last update')->sortable()
                ->tooltip(fn ($record) => $record->updated_at?->format('d M Y, H:i'))
                ->toggleable(),
        ];

        return $table
            ->persistFiltersInSession()
            ->filtersLayout(config('davyas.visual_v2')
                ? FiltersLayout::AboveContent
                : FiltersLayout::Dropdown)
            ->columns(array_merge($baseColumns, (new DynamicTableColumns)->build()))
            ->filters([
                SelectFilter::make('owner_id')->relationship('owner', 'name'),
                SelectFilter::make('referrer_id')->label('Referrer')->relationship('referrer', 'name'),
                SelectFilter::make('stage')->options(fn () => self::stageOptions()),
                SelectFilter::make('pipeline_status')
                    ->label('Pipeline status')
                    ->options([
                        'past_capture' => 'Past Lead Captured',
                        'active' => 'Active',
                        'admitted' => 'Admitted',
                        'closed_lost' => 'Closed (lost)',
                    ])
                    ->query(function ($query, array $data) {
                        $v = $data['value'] ?? null;
                        if ($v === null || $v === '') {
                            return $query;
                        }
                        if ($v === 'past_capture') {
                            return $query->where('stage', '!=', PipelineSummary::STAGE_LEAD_CAPTURED);
                        }
                        if ($v === 'admitted') {
                            return $query->where(function ($q) {
                                $q->where('stage', PipelineSummary::STAGE_SEAT_ALLOTTED)
                                    ->orWhere(function ($qq) {
                                        $qq->where('stage', PipelineSummary::STAGE_CLOSED)
                                            ->where('close_reason', PipelineSummary::CLOSE_REASON_COMPLETED);
                                    });
                            });
                        }
                        if ($v === 'closed_lost') {
                            return $query->where('stage', PipelineSummary::STAGE_CLOSED)
                                ->where(function ($q) {
                                    $q->whereNull('close_reason')
                                        ->orWhere('close_reason', '!=', PipelineSummary::CLOSE_REASON_COMPLETED);
                                });
                        }
                        if ($v === 'active') {
                            return $query
                                ->where('stage', '!=', PipelineSummary::STAGE_LEAD_CAPTURED)
                                ->where('stage', '!=', PipelineSummary::STAGE_SEAT_ALLOTTED)
                                ->where(function ($q) {
                                    $q->where('stage', '!=', PipelineSummary::STAGE_CLOSED)
                                        ->orWhereNull('stage');
                                });
                        }

                        return $query;
                    }),
                SelectFilter::make('plan')->options(fn () => self::optionsFor('plan', ['Sitting', 'Counselling Online', 'Counselling Offline'])),
                SelectFilter::make('course')
                    ->options(fn () => Student::query()->whereNotNull('course')->where('course', '!=', '')
                        ->distinct()->orderBy('course')->pluck('course', 'course')->all()),
                SelectFilter::make('current_round')->label('Round')
                    ->options(fn () => Student::query()->whereNotNull('current_round')->where('current_round', '!=', '')
                        ->distinct()->orderBy('current_round')->pluck('current_round', 'current_round')->all()),
                SelectFilter::make('lead_source')->label('Lead source')
                    ->options(fn () => Student::query()->whereNotNull('lead_source')->where('lead_source', '!=', '')
                        ->distinct()->orderBy('lead_source')->pluck('lead_source', 'lead_source')->all()),
                SelectFilter::make('category')
                    ->options(fn () => self::optionsFor('category', ['Delhi', 'Outside'])),
                SelectFilter::make('student_response')->label('Response')
                    ->options(fn () => self::optionsFor('student_response', ['Ready', 'Not Interested', 'Needs Time'])),
                Tables\Filters\Filter::make('has_pending')
                    ->label('Has pending amount')
                    ->query(fn ($query) => $query->whereRaw('deal_amount > COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = students.id), 0)')),
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
                    ->query(fn ($query) => $query->whereIn('id', RoundHistory::reEntryCandidates()->pluck('student_id'))),
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
                        ->action(function (Collection $records, array $data): void {
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

                            Notification::make()
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
            NotesRelationManager::class,
            ActivityRelationManager::class,
            MeetingsRelationManager::class,
            RoundHistoryRelationManager::class,
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
