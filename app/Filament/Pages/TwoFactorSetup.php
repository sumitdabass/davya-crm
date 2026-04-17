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

class TwoFactorSetup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Two-Factor Auth';

    protected static ?string $navigationGroup = 'Settings';

    protected static string $view = 'filament.pages.two-factor-setup';

    protected static ?string $slug = 'two-factor-setup';

    public ?array $data = [];

    // Session-stored pending-enable state (secret shown as QR; not persisted until verified).
    public ?string $pendingSecret = null;
    public ?string $pendingOtpauthUri = null;

    /** @var array<int, string>|null */
    public ?array $recoveryCodes = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Code from authenticator app')
                    ->placeholder('6 digits')
                    ->maxLength(6),
            ])
            ->statePath('data');
    }

    public function startEnroll(): void
    {
        $user = auth()->user();
        if ($user->hasTwoFactorEnabled()) {
            Notification::make()->title('Already enabled')->warning()->send();
            return;
        }

        $service = app(TwoFactorService::class);
        $this->pendingSecret = $service->generateSecret();
        $this->pendingOtpauthUri = $service->otpauthUri($user, $this->pendingSecret);
    }

    public function cancelEnroll(): void
    {
        $this->pendingSecret = null;
        $this->pendingOtpauthUri = null;
        $this->recoveryCodes = null;
        $this->form->fill(['code' => '']);
    }

    public function confirmEnroll(): void
    {
        if (! $this->pendingSecret) {
            Notification::make()->title('Start the setup first')->danger()->send();
            return;
        }

        $data = $this->form->getState();
        $service = app(TwoFactorService::class);

        if (! $service->verifyCode($this->pendingSecret, $data['code'] ?? '')) {
            Notification::make()->title('Wrong code')->body('Double-check your authenticator app and try again.')->danger()->send();
            return;
        }

        $codes = $service->generateRecoveryCodes();

        $user = auth()->user();
        $user->totp_secret = $this->pendingSecret;
        $user->totp_confirmed_at = now();
        $user->totp_recovery_codes = json_encode($codes);
        $user->save();

        session(['two_factor_verified' => true]);

        $this->recoveryCodes = $codes;
        $this->pendingSecret = null;
        $this->pendingOtpauthUri = null;
        $this->form->fill(['code' => '']);

        Notification::make()->title('2FA enabled')->success()->send();
    }

    public function disableTwoFactor(): void
    {
        $user = auth()->user();
        $user->totp_secret = null;
        $user->totp_confirmed_at = null;
        $user->totp_recovery_codes = null;
        $user->save();

        session()->forget('two_factor_verified');

        Notification::make()->title('2FA disabled')->warning()->send();
    }

    public function qrSvg(): string
    {
        if (! $this->pendingOtpauthUri) {
            return '';
        }
        return app(TwoFactorService::class)->qrSvg($this->pendingOtpauthUri);
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $actions = [];

        if ($user?->hasTwoFactorEnabled()) {
            $actions[] = Action::make('disable')
                ->label('Disable 2FA')
                ->color('danger')
                ->icon('heroicon-m-shield-exclamation')
                ->requiresConfirmation()
                ->action('disableTwoFactor');
        }

        return $actions;
    }
}
