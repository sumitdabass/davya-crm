<?php

namespace App\Filament\Pages;

use App\Services\TwoFactorService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TwoFactorChallenge extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.two-factor-challenge';

    protected static ?string $slug = 'two-factor-challenge';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            redirect()->to('/admin');
            return;
        }
        if (session('two_factor_verified') === true) {
            redirect()->to('/admin');
            return;
        }
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Authentication code')
                    ->placeholder('6-digit app code or 10-char recovery code')
                    ->required()
                    ->autofocus()
                    ->maxLength(10),
            ])
            ->statePath('data');
    }

    public function verify(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();
        $service = app(TwoFactorService::class);

        $code = trim($data['code']);

        // Try TOTP first
        if (strlen(preg_replace('/\D+/', '', $code)) === 6
            && $service->verifyCode((string) $user->totp_secret, $code)) {
            session(['two_factor_verified' => true]);
            redirect()->intended('/admin');
            return;
        }

        // Fall back to recovery code
        if ($service->consumeRecoveryCode($user, $code)) {
            session(['two_factor_verified' => true]);
            Notification::make()
                ->title('Recovery code accepted')
                ->body('That code is now used up. Consider regenerating codes.')
                ->warning()
                ->send();
            redirect()->intended('/admin');
            return;
        }

        Notification::make()->title('Invalid code')->danger()->send();
        $this->form->fill(['code' => '']);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('verify')
                ->label('Verify')
                ->submit('verify'),
        ];
    }
}
