<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;

class UserRegisteredMail extends Mailable
{
    public function __construct(private readonly User $user)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('会員登録が完了しました')
            ->text('mail.user_registered', [
                'user' => $this->user,
                'frontendUrl' => config('app.frontend_url'),
            ]);
    }
}
