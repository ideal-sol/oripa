<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class LinePendingFollow extends Model
{
    protected $table = 'line_pending_follows';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'followed_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'unfollowed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
