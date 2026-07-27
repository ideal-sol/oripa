<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BulkDrawRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->resource->bulkSummary;

        return [
            'bulk_request_id' => $this->public_id,
            'requested_count' => (int) $this->draw_count,
            'executed_count' => (int) $this->results()->count(),
            'consumed_point' => (int) $this->consumed_point_total,
            'prize_counts' => $summary['prize_counts'] ?? [],
            'rank_counts' => $summary['rank_counts'] ?? [],
            'high_rank_results' => $summary['high_rank_results'] ?? [],
            'high_rank_results_truncated' => $summary['high_rank_results_truncated'] ?? false,
            'status' => $this->status?->value ?? $this->status,
            'idempotent_replay' => $this->resource->idempotentReplay,
            'processing_duration_ms' => (int) $this->processing_duration_ms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
