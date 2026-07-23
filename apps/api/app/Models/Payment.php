<?php

namespace App\Models;

use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_payment_id',
        'webhook_event_id',
        'status',
        'amount',
        'paid_point_amount',
        'free_point_amount',
        'currency',
        'metadata',
        'paid_at',
        'refunded_at',
        'chargeback_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'chargeback_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reversal()
    {
        return $this->hasOne(PaymentReversal::class);
    }

    public function reversals()
    {
        return $this->hasMany(PaymentReversal::class);
    }
}
