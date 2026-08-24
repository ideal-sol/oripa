<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class MailDelivery extends Model
{
    protected $table = 'mail_deliveries';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
