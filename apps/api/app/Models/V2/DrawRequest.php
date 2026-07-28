<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class DrawRequest extends Model
{
    public $timestamps = false;

    protected $table = 'draw_requests';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'requested_count' => 'integer',
            'executed_count' => 'integer',
            'point_cost_total' => 'integer',
            'consumed_paid_points' => 'integer',
            'consumed_free_points' => 'integer',
            'wallet_paid_after' => 'integer',
            'wallet_free_after' => 'integer',
            'point_back_total' => 'integer',
            'is_qa_draw' => 'boolean',
            'processing_duration_ms' => 'integer',
            'response_data' => 'array',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
