<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    public const ISSUER = 'Davya CRM';
    public const RECOVERY_CODE_COUNT = 10;

    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\D+/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        // Accept one time-window either side of now (±30s) to forgive clock drift.
        return (bool) $this->google2fa->verifyKey($secret, $code, window: 1);
    }

    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $user->email, $secret);
    }

    public function qrSvg(string $otpauthUri, int $size = 220): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd);
        return (new Writer($renderer))->writeString($otpauthUri);
    }

    /**
     * @return array<int, string> 10 codes, each 10 chars, user-readable. Must be shown exactly once.
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }
        return $codes;
    }

    /**
     * Attempt to consume a recovery code. Returns true if consumed; false otherwise.
     * On true, the code is removed from the user's stored list.
     */
    public function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $candidate = strtoupper(trim($candidate));
        $codes = $user->totp_recovery_codes ? (array) json_decode($user->totp_recovery_codes, true) : [];

        $index = array_search($candidate, $codes, true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->totp_recovery_codes = json_encode(array_values($codes));
        $user->save();

        return true;
    }
}
