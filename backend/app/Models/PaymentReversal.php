<?php

namespace App\Models;

use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use Illuminate\Database\Eloquent\Model;

class PaymentReversal extends Model
{
    protected $fillable = [
        'payment_id',
        'user_id',
        'admin_user_id',
        'type',
        'status',
        'reason',
        'payment_amount',
        'paid_point_amount',
        'free_point_amount',
        'paid_reversed_amount',
        'free_reversed_amount',
        'shortfall_paid_amount',
        'shortfall_free_amount',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentReversalType::class,
            'status' => PaymentReversalStatus::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function pointEntries()
    {
        return $this->hasMany(PaymentReversalPointEntry::class);
    }

    public function prizeActions()
    {
        return $this->hasMany(PaymentReversalPrizeAction::class);
    }
}
