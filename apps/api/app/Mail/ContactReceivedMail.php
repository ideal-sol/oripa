<?php

namespace App\Mail;

use App\Models\ContactRequest;
use Illuminate\Mail\Mailable;

class ContactReceivedMail extends Mailable
{
    public function __construct(private readonly ContactRequest $contactRequest)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('お問い合わせを受け付けました')
            ->text('mail.contact_received', [
                'contactRequest' => $this->contactRequest,
                'frontendUrl' => config('app.frontend_url'),
            ]);
    }
}
