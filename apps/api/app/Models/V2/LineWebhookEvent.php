<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class LineWebhookEvent extends Model
{
    protected $table = 'line_webhook_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'reply_attempted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
