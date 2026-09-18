<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class PrizeInventory extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $table = 'prize_inventories';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_quantity' => 'integer',
            'awarded_count' => 'integer',
            'available_quantity' => 'integer',
            'withdrawn_quantity' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
