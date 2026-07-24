<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class PointLot extends Model
{
    protected $table = 'point_lots';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'granted_amount' => 'integer',
            'remaining_amount' => 'integer',
            'reserved_amount' => 'integer',
            'granted_at' => 'immutable_datetime',
            'expire_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
