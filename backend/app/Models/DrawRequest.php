<?php

namespace App\Models;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DrawRequest extends Model
{
    public bool $idempotentReplay = false;

    public array $bulkSummary = [];

    protected $fillable = [
        'public_id',
        'user_id',
        'gacha_id',
        'draw_count',
        'idempotency_key',
        'request_hash',
        'idempotency_expires_at',
        'status',
        'consumed_point_total',
        'processing_duration_ms',
        'is_qa_draw',
        'qa_test_user_mode_id',
        'qa_draw_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DrawRequestStatus::class,
            'is_qa_draw' => 'boolean',
            'idempotency_expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DrawRequest $drawRequest): void {
            $drawRequest->public_id ??= (string) Str::uuid();
        });
    }

    public function isBulk(): bool
    {
        return in_array((int) $this->draw_count, [100, 1000], true);
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

    public function qaTestUserMode()
    {
        return $this->belongsTo(QaTestUserMode::class);
    }

    public function qaDrawPlan()
    {
        return $this->belongsTo(QaDrawPlan::class);
    }

    public function qaDrawExecution()
    {
        return $this->hasOne(QaDrawExecution::class);
    }
}
