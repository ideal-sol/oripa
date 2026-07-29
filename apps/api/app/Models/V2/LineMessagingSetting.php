<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class LineMessagingSetting extends Model
{
    protected $table = 'line_messaging_settings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'reward_point_amount' => 'integer',
            'reward_expiration_days' => 'integer',
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
