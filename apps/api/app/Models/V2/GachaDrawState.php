<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class GachaDrawState extends Model
{
    protected $table = 'gacha_draw_states';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'sold_count' => 'integer',
            'lock_version' => 'integer',
            'started_at' => 'immutable_datetime',
            'sold_out_at' => 'immutable_datetime',
        ];
    }
}
