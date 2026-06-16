<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        // Filament's built-in delete stays off — it would hit an FK RESTRICT on
        // students.owner_id / payments.recorded_by_user_id / meetings.owner_id etc.
        // Admins use the custom "Delete" row action below, which reassigns first.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<string,int> */
    protected static function ownedRecordCounts(User $user): array
    {
        return [
            'students_owned' => Student::where('owner_id', $user->id)->count(),
            'students_referred' => Student::where('referrer_id', $user->id)->count(),
            'payments' => Payment::where('recorded_by_user_id', $user->id)->count(),
            'meetings_owned' => Meeting::where('owner_id', $user->id)->count(),
            'meetings_created' => Meeting::where('created_by_id', $user->id)->count(),
            'notes' => DB::table('student_notes')->where('author_id', $user->id)->count(),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Forms\Components\Select::make('team_head_id')
                ->label('Team head')
                ->options(fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'head'))->pluck('name', 'id'))
                ->searchable()
                ->nullable(),
            Forms\Components\Toggle::make('is_freelancer'),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\Toggle::make('must_change_password')->default(true)->helperText('Forces user to change password on next login'),
            Forms\Components\TextInput::make('password')
                ->password()
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $context) => $context === 'create')
                ->helperText('Leave blank to keep existing password on edit'),
            Forms\Components\Select::make('roles')
                ->multiple()
                ->relationship('roles', 'name')
                ->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->badge()->label('Roles'),
                Tables\Columns\TextColumn::make('teamHead.name')->label('Head'),
                Tables\Columns\IconColumn::make('is_freelancer')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\IconColumn::make('must_change_password')->boolean()->label('Must change pw'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TernaryFilter::make('is_freelancer'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('reset_2fa')
                    ->label('Reset 2FA')
                    ->icon('heroicon-m-shield-exclamation')
                    ->color('warning')
                    ->visible(fn (User $record) => auth()->user()?->hasRole('admin') && $record->hasTwoFactorEnabled())
                    ->requiresConfirmation()
                    ->modalHeading('Reset 2FA for this user?')
                    ->modalDescription(fn (User $record) => "This will wipe {$record->name}'s authenticator secret and recovery codes. They will log in with just a password, then can re-enrol from Settings → Two-Factor Auth.")
                    ->action(function (User $record) {
                        $record->totp_secret = null;
                        $record->totp_confirmed_at = null;
                        $record->totp_recovery_codes = null;
                        $record->save();

                        Log::info('admin.reset_2fa', [
                            'admin_id' => auth()->id(),
                            'target_user_id' => $record->id,
                        ]);

                        Notification::make()->title("2FA reset for {$record->name}")->success()->send();
                    }),
                Action::make('set_temp_password')
                    ->label('Set temp password')
                    ->icon('heroicon-m-key')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->hasRole('admin'))
                    ->requiresConfirmation()
                    ->modalHeading('Set a temporary password?')
                    ->modalDescription(fn (User $record) => "Generates a new random password for {$record->name} and forces them to change it on next login. Copy the password and share it through a secure channel.")
                    ->action(function (User $record) {
                        $temp = 'Temp-'.bin2hex(random_bytes(4)).'!';
                        $record->password = Hash::make($temp);
                        $record->must_change_password = true;
                        $record->save();

                        Log::info('admin.set_temp_password', [
                            'admin_id' => auth()->id(),
                            'target_user_id' => $record->id,
                        ]);

                        Notification::make()
                            ->title("Temp password for {$record->name}")
                            ->body("Password: $temp — copy now; it won't be shown again. Shown only to you.")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Action::make('delete_user')
                    ->label('Delete')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->visible(fn (User $record) => (auth()->user()?->isSuperAdmin() ?? false) && auth()->id() !== $record->id)
                    ->modalHeading(fn (User $record) => "Delete {$record->name}?")
                    ->modalDescription(function (User $record) {
                        $c = static::ownedRecordCounts($record);
                        $total = array_sum($c);
                        if ($total === 0) {
                            return "{$record->name} owns no students, payments, meetings, or notes. Safe to delete.";
                        }

                        return sprintf(
                            '%s owns: %d students (as owner), %d students (as referrer), %d payments, %d meetings (as owner), %d meetings (as creator), %d notes. Pick a replacement user below — all of these will be reassigned in a single transaction, then the account will be deleted.',
                            $record->name,
                            $c['students_owned'],
                            $c['students_referred'],
                            $c['payments'],
                            $c['meetings_owned'],
                            $c['meetings_created'],
                            $c['notes'],
                        );
                    })
                    ->form(function (User $record) {
                        if (array_sum(static::ownedRecordCounts($record)) === 0) {
                            return [];
                        }

                        return [
                            Forms\Components\Select::make('reassign_to')
                                ->label('Reassign all records to')
                                ->options(fn () => User::where('id', '!=', $record->id)
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ];
                    })
                    ->requiresConfirmation()
                    ->modalSubmitActionLabel('Delete user')
                    ->action(function (User $record, array $data) {
                        $targetId = isset($data['reassign_to']) ? (int) $data['reassign_to'] : null;

                        DB::transaction(function () use ($record, $targetId) {
                            if ($targetId !== null) {
                                DB::table('students')->where('owner_id', $record->id)->update(['owner_id' => $targetId]);
                                DB::table('students')->where('referrer_id', $record->id)->update(['referrer_id' => $targetId]);
                                DB::table('payments')->where('recorded_by_user_id', $record->id)->update(['recorded_by_user_id' => $targetId]);
                                DB::table('student_notes')->where('author_id', $record->id)->update(['author_id' => $targetId]);
                                DB::table('meetings')->where('owner_id', $record->id)->update(['owner_id' => $targetId]);
                                DB::table('meetings')->where('created_by_id', $record->id)->update(['created_by_id' => $targetId]);
                            }
                            $record->delete();
                        });

                        Log::info('admin.delete_user', [
                            'admin_id' => auth()->id(),
                            'deleted_user_id' => $record->id,
                            'deleted_user_name' => $record->name,
                            'reassigned_to' => $targetId,
                        ]);

                        Notification::make()
                            ->title("User {$record->name} deleted")
                            ->body($targetId ? 'Owned records reassigned before deletion.' : 'No records to reassign.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
