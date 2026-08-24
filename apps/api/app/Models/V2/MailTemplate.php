<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class MailTemplate extends Model
{
    protected $table = 'mail_templates';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
