<?php

namespace App\Mail;

use App\Models\ContactRequest;
use Illuminate\Mail\Mailable;

class ContactReplyMail extends Mailable
{
    public function __construct(private readonly ContactRequest $contactRequest)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('お問い合わせへのご返信')
            ->text('mail.contact_reply', [
                'contactRequest' => $this->contactRequest,
            ]);
    }
}
