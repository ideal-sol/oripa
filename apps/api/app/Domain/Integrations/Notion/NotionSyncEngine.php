<?php

namespace App\Domain\Integrations\Notion;

use App\Domain\Catalog\Services\V2CardInventoryService;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NotionSyncEngine
{
    public function __construct(
        private readonly V2CardInventoryService $inventory,
        private readonly NotionClient $client,
        private readonly NotionSchema $schema,
    ) {}

    public function run(object $run, callable $fence): void
    {
        $this->client->heartbeat($fence);
        $snapshot = $this->inventory->snapshotExport();
        try {
            $batch = [];
            while (($line = fgets($snapshot['stream'])) !== false) {
                $card = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($card) || ! is_string($card['external_id'] ?? null)
                    || ! preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $card['external_id'])) {
                    throw new NotionException('NOTION_SNAPSHOT_INVALID');
                }
                $record = ['run_id' => $run->id, 'external_id' => $card['external_id']];
                foreach (['required_quantity', 'allocation', 'stored', 'shipped'] as $field) {
                    if (! is_int($card[$field] ?? null) || $card[$field] < 0 || $card[$field] > 9007199254740991) {
                        throw new NotionException('NOTION_SNAPSHOT_INVALID');
                    }
                    $record[$field] = $card[$field];
                }
                if ($card['required_quantity'] !== $card['allocation'] + $card['stored']) {
                    throw new NotionException('NOTION_SNAPSHOT_INVALID');
                }
                $batch[] = $record;
                if (count($batch) === 100) {
                    $fence();
                    DB::table('notion_sync_snapshot')->insert($batch);
                    $batch = [];
                }
            }
            if (! feof($snapshot['stream'])) {
                throw new NotionException('NOTION_SNAPSHOT_INVALID');
            }
            $fence();
            if ($batch !== []) {
                DB::table('notion_sync_snapshot')->insert($batch);
            }
        } finally {
            fclose($snapshot['stream']);
        }
        DB::table('notion_sync_runs')->where('id', $run->id)->update(['unlinked_prize_count' => $snapshot['unlinked_prize_count']]);
        $mapping = $this->schema->validate($this->client->schema());
        $cursor = null;
        $cursors = [];
        $read = 0;
        do {
            $response = $this->client->query($cursor);
            if (($response['request_status']['type'] ?? null) !== 'complete' || ($response['object'] ?? null) !== 'list'
                || ! is_bool($response['has_more'] ?? null) || ! is_array($response['results'] ?? null)
                || count($response['results']) > 100) {
                throw new NotionException('NOTION_QUERY_INCOMPLETE');
            }
            $read += count($response['results']);
            if ($read > 10000) {
                throw new NotionException('NOTION_QUERY_INCOMPLETE');
            }
            $fence();
            foreach ($response['results'] as $page) {
                $parsed = $this->schema->page($page, $mapping);
                if ($parsed !== null) {
                    $parsed = $this->schema->page($this->client->completeExternalId($page, $mapping['external_id']), $mapping);
                    DB::table('notion_sync_pages')->insert([
                        ...$parsed, 'run_id' => $run->id, 'values' => json_encode($parsed['values'], JSON_THROW_ON_ERROR),
                        'read_at' => V2DatabaseTimestamp::format(now()),
                        'status' => $parsed['external_id'] === null ? 'invalid' : 'pending',
                    ]);
                }
            }
            DB::table('notion_sync_runs')->where('id', $run->id)->update(['read_count' => $read]);
            $cursor = $response['next_cursor'] ?? null;
            if ($response['has_more']) {
                if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 2000 || isset($cursors[$cursor]) || $read >= 10000) {
                    throw new NotionException('NOTION_QUERY_INCOMPLETE');
                }
                $cursors[$cursor] = true;
            } elseif ($cursor !== null) {
                throw new NotionException('NOTION_QUERY_INCOMPLETE');
            }
        } while ($response['has_more']);

        $fence();
        $duplicates = DB::table('notion_sync_pages')->where('run_id', $run->id)->whereNotNull('external_id')
            ->groupBy('external_id')->havingRaw('count(*) > 1')->pluck('external_id');
        DB::table('notion_sync_pages')->where('run_id', $run->id)->whereIn('external_id', $duplicates)->update(['status' => 'duplicate']);
        $missing = DB::table('notion_sync_snapshot as snapshot')->where('snapshot.run_id', $run->id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('notion_sync_pages as page')
                ->whereColumn('page.run_id', 'snapshot.run_id')->whereColumn('page.external_id', 'snapshot.external_id'))->count();
        DB::table('notion_sync_runs')->where('id', $run->id)->update([
            'duplicate_count' => $duplicates->count(), 'missing_count' => $missing,
            'invalid_count' => DB::table('notion_sync_pages')->where('run_id', $run->id)->where('status', 'invalid')->count(),
        ]);
        DB::table('notion_inventory_cache')->whereNotIn('external_id', DB::table('notion_sync_pages')
            ->select('external_id')->where('run_id', $run->id)->where('status', 'pending')->whereNotNull('external_id'))->delete();
        foreach (DB::table('notion_sync_pages')->where('run_id', $run->id)->where('status', 'pending')->lazyById(100) as $page) {
            $fence();
            $target = DB::table('notion_sync_snapshot')->where('run_id', $run->id)->where('external_id', $page->external_id)->first();
            $values = json_decode($page->values, true, 32, JSON_THROW_ON_ERROR);
            $properties = [];
            foreach (['required_quantity', 'allocation', 'stored', 'shipped'] as $field) {
                if (! isset($mapping[$field])) {
                    continue;
                }
                $quantity = (int) ($target->$field ?? 0);
                if (! isset($values[$field]) || (float) $values[$field] !== (float) $quantity) {
                    $properties[$mapping[$field]['name']] = ['number' => $quantity];
                }
            }
            try {
                if ($properties !== []) {
                    if (isset($mapping['synced_at'])) {
                        $properties[$mapping['synced_at']['name']] = ['date' => ['start' => now()->toIso8601String()]];
                    }
                    $this->client->patch($page->page_id, $properties);
                    $page->available = null;
                }
                $fence();
                DB::transaction(function () use ($run, $page, $properties, $fence): void {
                    $fence();
                    DB::table('notion_inventory_cache')->updateOrInsert(['external_id' => $page->external_id], [
                        'page_id' => $page->page_id, 'on_hand' => $page->on_hand, 'available' => $page->available,
                        'read_at' => $page->read_at, 'run_id' => $run->id,
                    ]);
                    $status = $properties === [] ? 'unchanged' : 'updated';
                    DB::table('notion_sync_pages')->where('id', $page->id)->update(['status' => $status]);
                    DB::table('notion_sync_runs')->where('id', $run->id)->increment($status.'_count');
                });
            } catch (Throwable $exception) {
                $fence();
                DB::transaction(function () use ($page, $run, $exception, $fence): void {
                    $fence();
                    DB::table('notion_sync_pages')->where('id', $page->id)->update([
                        'status' => 'failed', 'error_code' => $exception instanceof NotionException ? $exception->errorCode : 'NOTION_REQUEST_FAILED',
                    ]);
                    DB::table('notion_inventory_cache')->where('external_id', $page->external_id)->update(['available' => null]);
                    DB::table('notion_sync_runs')->where('id', $run->id)->increment('failed_count');
                });
            }
        }
        if (DB::table('notion_sync_runs')->where('id', $run->id)->value('failed_count') > 0) {
            throw new NotionException('NOTION_PARTIAL_FAILURE');
        }
    }
}
