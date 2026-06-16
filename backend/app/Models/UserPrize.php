<?php

namespace App\Models;

use App\Domain\Shipping\Enums\UserPrizeStatus;
use Illuminate\Database\Eloquent\Model;

class UserPrize extends Model
{
    protected $fillable = [
        'user_id',
        'gacha_id',
        'gacha_prize_id',
        'draw_result_id',
        'status',
        'acquired_at',
        'storage_expire_at',
        'converted_point',
    ];

    protected function casts(): array
    {
        return [
            'status' => UserPrizeStatus::class,
            'acquired_at' => 'datetime',
            'storage_expire_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function prize()
    {
        return $this->belongsTo(GachaPrize::class, 'gacha_prize_id');
    }

    public function drawResult()
    {
        return $this->belongsTo(DrawResult::class);
    }
}
