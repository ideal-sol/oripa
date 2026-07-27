<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class PrizeInventory extends Model
{
    protected $table = 'prize_inventories';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'initial_quantity' => 'integer',
            'won_count' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
