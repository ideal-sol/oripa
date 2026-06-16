<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GachaPrize extends Model
{
    use HasFactory;

    protected $fillable = [
        'gacha_id',
        'rank_id',
        'name',
        'image_url',
        'max_win_count',
        'won_count',
        'cost_price',
        'display_price',
        'exchange_point',
        'condition',
        'is_active',
        'is_visible',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function rank()
    {
        return $this->belongsTo(GachaRank::class, 'rank_id');
    }
}
