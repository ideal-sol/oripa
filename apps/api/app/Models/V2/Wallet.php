<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class Wallet extends Model
{
    protected $table = 'wallets';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'paid_balance' => 'integer',
            'free_balance' => 'integer',
            'paid_reserved_balance' => 'integer',
            'free_reserved_balance' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
