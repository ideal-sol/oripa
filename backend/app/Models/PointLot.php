<?php

namespace App\Models;

use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use Illuminate\Database\Eloquent\Model;

class PointLot extends Model
{
    protected $fillable = [
        'user_id',
        'point_type',
        'granted_amount',
        'remaining_amount',
        'source_type',
        'source_id',
        'granted_at',
        'expire_at',
    ];

    protected function casts(): array
    {
        return [
            'point_type' => PointType::class,
            'source_type' => PointLotSourceType::class,
            'granted_at' => 'datetime',
            'expire_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledgers()
    {
        return $this->hasMany(PointLedger::class);
    }

    public function paymentReversalPointEntries()
    {
        return $this->hasMany(PaymentReversalPointEntry::class);
    }
}
