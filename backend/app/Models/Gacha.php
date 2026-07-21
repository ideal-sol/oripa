<?php

namespace App\Models;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gacha extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'category_id',
        'price',
        'total_count',
        'daily_draw_limit',
        'sold_count',
        'probability_mode',
        'current_probability_version_id',
        'minimum_guarantee_type',
        'minimum_guarantee_value',
        'minimum_guarantee_cost',
        'status',
        'start_at',
        'end_at',
        'description',
        'caution',
        'main_image_url',
        'show_on_top_slider',
        'target_margin',
    ];

    protected function casts(): array
    {
        return [
            'probability_mode' => ProbabilityMode::class,
            'minimum_guarantee_type' => MinimumGuaranteeType::class,
            'status' => GachaStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'show_on_top_slider' => 'boolean',
            'target_margin' => 'decimal:2',
        ];
    }

    public function category()
    {
        return $this->belongsTo(GachaCategory::class, 'category_id');
    }

    public function ranks()
    {
        return $this->hasMany(GachaRank::class);
    }

    public function prizes()
    {
        return $this->hasMany(GachaPrize::class);
    }

    public function tags()
    {
        return $this->belongsToMany(GachaTag::class, 'gacha_tag_assignments', 'gacha_id', 'gacha_tag_id')
            ->withTimestamps()
            ->orderBy('gacha_tags.sort_order')
            ->orderBy('gacha_tags.id');
    }

    public function currentProbabilityVersion()
    {
        return $this->belongsTo(GachaProbabilityVersion::class, 'current_probability_version_id');
    }

    public function qaDrawPlans()
    {
        return $this->hasMany(QaDrawPlan::class);
    }

    public function qaDrawExecutions()
    {
        return $this->hasMany(QaDrawExecution::class);
    }
}
