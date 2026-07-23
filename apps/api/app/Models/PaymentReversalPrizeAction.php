<?php

namespace App\Models;

use App\Domain\Payment\Enums\PaymentReversalPrizeActionStatus;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use Illuminate\Database\Eloquent\Model;

class PaymentReversalPrizeAction extends Model
{
    protected $fillable = [
        'payment_reversal_id',
        'user_prize_id',
        'shipping_item_id',
        'action_type',
        'previous_user_prize_status',
        'previous_shipping_item_status',
        'status',
        'note',
        'mail_sent_at',
        'mail_last_error',
        'mail_last_attempted_at',
        'discord_last_error',
        'discord_last_attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => PaymentReversalPrizeActionType::class,
            'status' => PaymentReversalPrizeActionStatus::class,
            'mail_sent_at' => 'datetime',
            'mail_last_attempted_at' => 'datetime',
            'discord_last_attempted_at' => 'datetime',
        ];
    }

    public function paymentReversal()
    {
        return $this->belongsTo(PaymentReversal::class);
    }

    public function userPrize()
    {
        return $this->belongsTo(UserPrize::class);
    }

    public function shippingItem()
    {
        return $this->belongsTo(ShippingItem::class);
    }
}
