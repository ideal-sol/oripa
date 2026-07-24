<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Models\V2\User;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

final class V2MailEmailVerificationNotifier implements V2EmailVerificationNotifier
{
    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath,
        string $deduplicationKey
    ): void {
        $path = '/api/v2/auth/email/verify/'.$user->public_id.'/'.$token
            .'?redirect='.rawurlencode($redirectPath);

        Mail::raw(
            "V2 email verification path:\n{$path}\n\nThis link expires in 60 minutes.",
            static function ($message) use ($user): void {
                $message
                    ->to($user->email_display)
                    ->subject('メールアドレス確認');
            }
        );
    }
}
