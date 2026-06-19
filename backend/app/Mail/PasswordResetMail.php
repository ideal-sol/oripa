<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;

class PasswordResetMail extends Mailable
{
    public function __construct(
        private readonly User $user,
        private readonly string $token,
    ) {
    }

    public function build(): self
    {
        $resetUrl = rtrim((string) config('app.frontend_url'), '/').'/login?reset_token='.urlencode($this->token).'&email='.urlencode($this->user->email);

        return $this
            ->subject('パスワード再設定のご案内')
            ->text('mail.password_reset', [
                'user' => $this->user,
                'resetUrl' => $resetUrl,
                'expireMinutes' => config('auth.passwords.users.expire'),
            ]);
    }
}
