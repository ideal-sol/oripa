<?php

namespace App\Models;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class GachaProbabilityVersion extends Model
{
    protected $fillable = [
        'gacha_id',
        'version_number',
        'status',
        'snapshot_hash',
        'published_at',
        'published_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProbabilityVersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') === ProbabilityVersionStatus::Published->value) {
                throw new LogicException('Published probability versions are immutable.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->status === ProbabilityVersionStatus::Published) {
                throw new LogicException('Published probability versions are immutable.');
            }
        });
    }

    public function stages()
    {
        return $this->hasMany(GachaProbabilityVersionStage::class, 'probability_version_id');
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }
}
