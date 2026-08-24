<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Models\V2\User;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class V2MailEmailVerificationNotifier implements V2EmailVerificationNotifier
{
    public function __construct(private readonly V2TemplateMailDeliveryService $mail)
    {
    }

    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath,
        string $deduplicationKey
    ): void {
        $url = $this->publicOrigin().'/api/v2/auth/email/verify/'.$user->public_id.'/'.$token
            .'?redirect='.rawurlencode($redirectPath);

        try {
            $this->mail->sendVerification($user->email_display, [
                'user_name' => $user->display_name ?? '',
                'full_name' => $user->display_name ?? '',
                'verification_url' => $url,
            ]);
        } catch (Throwable) {
            throw new V2AuthenticationException(
                'VERIFICATION_MAIL_DELIVERY_FAILED',
                503,
                'The verification email could not be delivered.',
                true
            );
        }
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
