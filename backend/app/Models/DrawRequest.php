<?php

namespace App\Models;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use Illuminate\Database\Eloquent\Model;

class DrawRequest extends Model
{
    protected $fillable = [
        'user_id',
        'gacha_id',
        'draw_count',
        'idempotency_key',
        'status',
        'consumed_point_total',
    ];

    protected function casts(): array
    {
        return [
            'status' => DrawRequestStatus::class,
        ];
    }

    public function results()
    {
        return $this->hasMany(DrawResult::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }
}
