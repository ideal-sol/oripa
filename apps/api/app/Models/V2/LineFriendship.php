<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class LineFriendship extends Model
{
    protected $table = 'line_friendships';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'followed_at' => 'immutable_datetime',
            'unfollowed_at' => 'immutable_datetime',
            'rewarded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
