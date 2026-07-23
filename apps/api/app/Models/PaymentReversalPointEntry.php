<?php

namespace App\Models;

use App\Domain\Payment\Enums\PaymentReversalPointBucket;
use App\Domain\Point\Enums\PointType;
use Illuminate\Database\Eloquent\Model;

class PaymentReversalPointEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_reversal_id',
        'payment_id',
        'user_id',
        'point_lot_id',
        'point_ledger_id',
        'point_type',
        'bucket',
        'amount',
        'shortfall_amount',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'point_type' => PointType::class,
            'bucket' => PaymentReversalPointBucket::class,
            'created_at' => 'datetime',
        ];
    }

    public function paymentReversal()
    {
        return $this->belongsTo(PaymentReversal::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pointLot()
    {
        return $this->belongsTo(PointLot::class);
    }

    public function pointLedger()
    {
        return $this->belongsTo(PointLedger::class);
    }
}
