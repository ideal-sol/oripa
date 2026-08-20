<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Models\V2\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use SensitiveParameter;

final class V2MailEmailVerificationNotifier implements V2EmailVerificationNotifier
{
    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath,
        string $deduplicationKey
    ): void {
        $url = $this->publicOrigin().'/api/v2/auth/email/verify/'.$user->public_id.'/'.$token
            .'?redirect='.rawurlencode($redirectPath);

        Mail::raw(
            "V2 email verification URL:\n{$url}\n\nThis link expires in 60 minutes.",
            static function ($message) use ($user): void {
                $message
                    ->to($user->email_display)
                    ->subject('メールアドレス確認');
            }
        );
    }

    private function publicOrigin(): string
    {
        $origin = rtrim((string) config('v2_identity.origins.user'), '/');
        $parts = parse_url($origin);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || ($parts['path'] ?? '') !== ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException(
                'The canonical Public Origin must be an absolute HTTPS origin.'
            );
        }

        return $origin;
    }
}
