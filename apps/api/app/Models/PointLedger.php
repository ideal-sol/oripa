<?php

namespace App\Models;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use Illuminate\Database\Eloquent\Model;

class PointLedger extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'point_lot_id',
        'point_type',
        'ledger_type',
        'amount',
        'balance_after',
        'related_type',
        'related_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'point_type' => PointType::class,
            'ledger_type' => PointLedgerType::class,
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function pointLot()
    {
        return $this->belongsTo(PointLot::class);
    }

    public function paymentReversalPointEntries()
    {
        return $this->hasMany(PaymentReversalPointEntry::class);
    }
}
