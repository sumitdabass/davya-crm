<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static string $view = 'filament.pages.change-password';
    protected static ?string $slug = 'change-password';
    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('current_password')
                    ->password()
                    ->required()
                    ->currentPassword()
                    ->autocomplete('current-password'),
                TextInput::make('new_password')
                    ->password()
                    ->required()
                    ->minLength(10)
                    ->different('current_password')
                    ->autocomplete('new-password'),
                TextInput::make('new_password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->required()
                    ->same('new_password'),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();
        $user->forceFill([
            'password' => Hash::make($data['new_password']),
            'must_change_password' => false,
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('password_changed')
            ->log('password_changed');

        Notification::make()
            ->title('Password changed')
            ->success()
            ->send();

        $this->redirect('/admin');
    }
}
