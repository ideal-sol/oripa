<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GachaTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function gachas()
    {
        return $this->belongsToMany(Gacha::class, 'gacha_tag_assignments', 'gacha_tag_id', 'gacha_id')
            ->withTimestamps();
    }
}
