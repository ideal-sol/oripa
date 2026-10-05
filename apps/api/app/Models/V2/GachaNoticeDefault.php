<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class GachaNoticeDefault extends Model
{
    protected $table = 'gacha_notice_defaults';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
