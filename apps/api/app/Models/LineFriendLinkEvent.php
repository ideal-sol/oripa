<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineFriendLinkEvent extends Model
{
    protected $fillable = [
        'user_id',
        'line_friend_link_id',
        'line_user_id',
        'event_type',
        'message_text',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lineFriendLink()
    {
        return $this->belongsTo(LineFriendLink::class);
    }
}
