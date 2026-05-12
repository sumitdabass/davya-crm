<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityAudit extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Activity audit';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.activity-audit';

    protected static ?string $slug = 'activity-audit';

    protected static ?string $title = 'Activity Audit';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Activity::query()->latest())
            ->recordUrl(function (Activity $record): ?string {
                if ($record->subject_id === null) {
                    return null;
                }
                if ($record->subject_type === \App\Models\Student::class) {
                    return \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $record->subject_id]);
                }
                if ($record->subject_type === \App\Models\User::class) {
                    return \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $record->subject_id]);
                }
                if ($record->subject_type === \App\Models\Payment::class) {
                    $studentId = \App\Models\Payment::query()->whereKey($record->subject_id)->value('student_id');
                    return $studentId
                        ? \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $studentId])
                        : null;
                }
                return null;
            })
            ->columns([
                TextColumn::make('created_at')->label('When')->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i:s'))->sortable(),
                TextColumn::make('causer.name')->label('Who')->badge()->color('gray'),
                TextColumn::make('description')->label('What')->wrap(),
                TextColumn::make('subject_type')->label('Model')
                    ->formatStateUsing(fn ($state) => class_basename((string) $state))
                    ->badge()->color('info'),
                TextColumn::make('subject_id')->label('#'),
                TextColumn::make('event')->badge()->color(fn ($state) => match ($state) {
                    'created' => 'success',
                    'updated' => 'primary',
                    'deleted' => 'danger',
                    default   => 'gray',
                }),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Model')
                    ->options([
                        'App\\Models\\Student' => 'Student',
                        'App\\Models\\User'    => 'User',
                        'App\\Models\\Payment' => 'Payment',
                    ]),
                SelectFilter::make('event')->options([
                    'created' => 'created',
                    'updated' => 'updated',
                    'deleted' => 'deleted',
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
