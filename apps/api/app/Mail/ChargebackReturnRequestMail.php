<?php

namespace App\Mail;

use App\Models\PaymentReversal;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;

class ChargebackReturnRequestMail extends Mailable
{
    public function __construct(
        public readonly PaymentReversal $paymentReversal,
        public readonly Collection $actions,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('【重要】チャージバックに伴う返送依頼のご案内')
            ->text('mail.chargeback_return_request', [
                'paymentReversal' => $this->paymentReversal,
                'actions' => $this->actions,
                'user' => $this->paymentReversal->user,
                'frontendUrl' => config('app.frontend_url'),
            ]);
    }
}
