<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

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
        return (auth()->user()?->hasRole('admin') ?? false)
            && auth()->id() !== $record->id;
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
                ->preload()
                ->options(fn () => Role::pluck('name', 'name')),
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
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
