<?php

namespace App\Models;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Domain\Probability\Enums\StageConditionType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class GachaProbabilityVersionStage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'probability_version_id',
        'stage_key',
        'name',
        'condition_type',
        'min_draw_number',
        'max_draw_number',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'condition_type' => StageConditionType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $stage): bool => $stage->assertDraftVersion());
        static::deleting(fn (self $stage): bool => $stage->assertDraftVersion());
    }

    public function version()
    {
        return $this->belongsTo(GachaProbabilityVersion::class, 'probability_version_id');
    }

    public function probabilities()
    {
        return $this->hasMany(GachaProbabilityVersionPrizeProbability::class, 'probability_version_stage_id');
    }

    private function assertDraftVersion(): bool
    {
        $status = $this->version()->value('status');

        if ($status instanceof ProbabilityVersionStatus) {
            $status = $status->value;
        }

        if ($status === ProbabilityVersionStatus::Published->value) {
            throw new LogicException('Published probability version stages are immutable.');
        }

        return true;
    }
}
