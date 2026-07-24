<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PointLedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'point_ledger_entries';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('V2 point ledger entries are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 point ledger entries are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount_delta' => 'integer',
            'wallet_balance_after' => 'integer',
            'lot_remaining_after' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}
