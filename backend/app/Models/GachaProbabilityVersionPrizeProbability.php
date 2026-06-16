<?php

namespace App\Models;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class GachaProbabilityVersionPrizeProbability extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'probability_version_stage_id',
        'prize_id',
        'is_minimum_guarantee',
        'probability_ppm',
    ];

    protected function casts(): array
    {
        return [
            'is_minimum_guarantee' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $probability): bool => $probability->assertDraftVersion());
        static::deleting(fn (self $probability): bool => $probability->assertDraftVersion());
    }

    public function stage()
    {
        return $this->belongsTo(GachaProbabilityVersionStage::class, 'probability_version_stage_id');
    }

    public function prize()
    {
        return $this->belongsTo(GachaPrize::class, 'prize_id');
    }

    private function assertDraftVersion(): bool
    {
        $status = $this->stage()
            ->join('gacha_probability_versions', 'gacha_probability_versions.id', '=', 'gacha_probability_version_stages.probability_version_id')
            ->value('gacha_probability_versions.status');

        if ($status instanceof ProbabilityVersionStatus) {
            $status = $status->value;
        }

        if ($status === ProbabilityVersionStatus::Published->value) {
            throw new LogicException('Published probability rows are immutable.');
        }

        return true;
    }
}
