<?php

namespace App\Domain\Draw\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class V2DrawPresentationResolver
{
    public function resolve(Collection $results): ?array
    {
        if ($results->isEmpty()) {
            return null;
        }
        $revisions = DB::table('catalog_rank_master_revisions')
            ->whereIn('id', $results->pluck('rank_master_revision_id')->unique())
            ->get(['id', 'rank_master_id', 'display_order'])
            ->keyBy('id');
        $highestOrder = null;
        $highestResults = collect();
        $highestRankIds = [];
        foreach ($results as $result) {
            $revision = $revisions->get($result->rank_master_revision_id);
            if ($revision === null) {
                return null;
            }
            $order = (int) $revision->display_order;
            if ($highestOrder === null || $order < $highestOrder) {
                $highestOrder = $order;
                $highestResults = collect();
                $highestRankIds = [];
            }
            if ($order === $highestOrder) {
                $highestResults->push($result);
                $highestRankIds[(int) $revision->rank_master_id] = true;
            }
        }
        if (count($highestRankIds) !== 1) {
            return null;
        }
        $selected = $highestResults->sortBy('request_sequence')->first();
        $snapshot = $selected->display_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }
        $presentation = [
            'rank' => $snapshot['rank'] ?? null,
            'video_snapshot' => $snapshot['video_snapshot'] ?? null,
        ];
        if (Validator::make($presentation, [
            'rank' => ['required', 'array:id,name'],
            'rank.id' => ['required', 'string', 'max:128'],
            'rank.name' => ['required', 'string', 'max:128'],
            'video_snapshot' => ['required', 'array:id,path,checksum_sha256,media_type,mime_type,alt_text'],
            'video_snapshot.id' => ['required', 'string', 'max:128'],
            'video_snapshot.path' => ['required', 'string', 'max:512', 'regex:~^/(?!/)~'],
            'video_snapshot.checksum_sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'video_snapshot.media_type' => ['required', 'in:video'],
            'video_snapshot.mime_type' => ['required', 'string', 'max:128', 'starts_with:video/'],
            'video_snapshot.alt_text' => ['present', 'nullable', 'string', 'max:191'],
        ])->fails()) {
            return null;
        }

        return $presentation;
    }
}
