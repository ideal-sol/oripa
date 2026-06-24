<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;

class UserEmailVerificationMail extends Mailable
{
    public function __construct(
        private readonly User $user,
        private readonly string $verificationUrl,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('メールアドレス確認のご案内')
            ->text('mail.user_email_verification', [
                'user' => $this->user,
                'verificationUrl' => $this->verificationUrl,
                'expireHours' => 24,
            ]);
    }
}
