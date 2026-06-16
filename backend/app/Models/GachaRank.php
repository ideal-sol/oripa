<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GachaRank extends Model
{
    use HasFactory;

    protected $fillable = [
        'gacha_id',
        'rank_key',
        'display_name',
        'description',
        'image_url',
        'draw_video_url',
        'result_image_url',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function prizes()
    {
        return $this->hasMany(GachaPrize::class, 'rank_id');
    }
}
