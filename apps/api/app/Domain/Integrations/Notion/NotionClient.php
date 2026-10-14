<?php

namespace App\Domain\Integrations\Notion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;

final class NotionClient
{
    private $heartbeat;

    public function __construct(private readonly NotionConfig $config, private readonly NotionClock $clock)
    {
        $this->heartbeat = static fn () => null;
    }

    public function heartbeat(callable $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
    }

    public function schema(): array
    {
        $this->config->assertReady();

        return $this->request('GET', '/data_sources/'.config('v2_notion.data_source_id'));
    }

    public function query(?string $cursor, array $mapping): array
    {
        $this->config->assertReady();
        $filters = [];
        foreach (config('v2_notion.properties') as $field => $name) {
            if ($name === null || $name === '') {
                continue;
            }
            $definition = $mapping[$field] ?? null;
            if (($definition['name'] ?? null) !== $name || ! is_string($definition['id'] ?? null) || $definition['id'] === '') {
                throw new NotionException('NOTION_SCHEMA_MISMATCH');
            }
            $filters[] = 'filter_properties%5B%5D='.rawurlencode($definition['id']);
        }

        return $this->request('POST', '/data_sources/'.config('v2_notion.data_source_id').'/query?'.implode('&', $filters),
            ['page_size' => 100, ...($cursor === null ? [] : ['start_cursor' => $cursor])]);
    }

    public function page(string $pageId): array
    {
        $this->uuid($pageId);

        return $this->request('GET', '/pages/'.$pageId);
    }

    public function patch(string $pageId, array $properties): array
    {
        $this->uuid($pageId);
        $allowed = array_filter(array_intersect_key(config('v2_notion.properties'),
            array_flip(['required_quantity', 'allocation', 'stored', 'shipped', 'synced_at'])));
        foreach ($properties as $name => $value) {
            $field = array_search($name, $allowed, true);
            if ($field === false || ! is_array($value)
                || array_keys($value) !== [$field === 'synced_at' ? 'date' : 'number']) {
                throw new NotionException('NOTION_SCHEMA_MISMATCH');
            }
        }
        if ($properties === []) {
            throw new NotionException('NOTION_SCHEMA_MISMATCH');
        }

        return $this->request('PATCH', '/pages/'.$pageId, ['properties' => $properties], $pageId);
    }

    public function completeExternalId(array $page, array $definition): array
    {
        $type = $definition['type'];
        $name = $definition['name'];
        if (! in_array($type, ['title', 'rich_text'], true)
            || count($page['properties'][$name][$type] ?? []) < 25) {
            return $page;
        }
        $this->uuid($page['id']);
        $fragments = [];
        $cursor = null;
        $seen = [];
        do {
            $query = http_build_query(['page_size' => 100, ...($cursor === null ? [] : ['start_cursor' => $cursor])]);
            $response = $this->request('GET', '/pages/'.$page['id'].'/properties/'.rawurlencode(rawurldecode($definition['id'])).'?'.$query);
            if (($response['object'] ?? null) !== 'list' || ! is_bool($response['has_more'] ?? null)
                || ! is_array($response['results'] ?? null)) {
                throw new NotionException('NOTION_RESPONSE_INVALID');
            }
            foreach ($response['results'] as $item) {
                if (($item['type'] ?? null) !== $type || ! is_string($item[$type]['plain_text'] ?? null)) {
                    throw new NotionException('NOTION_RESPONSE_INVALID');
                }
                $fragments[] = $item[$type];
            }
            $cursor = $response['next_cursor'] ?? null;
            if (count($fragments) > 10000 || ($response['has_more']
                && (! is_string($cursor) || $cursor === '' || strlen($cursor) > 2000 || isset($seen[$cursor])))
                || (! $response['has_more'] && $cursor !== null)) {
                throw new NotionException('NOTION_RESPONSE_INVALID');
            }
            if ($cursor !== null) { $seen[$cursor] = true; }
        } while ($response['has_more']);
        $page['properties'][$name][$type] = $fragments;

        return $page;
    }

    private function request(string $method, string $path, array $body = [], ?string $pageId = null): array
    {
        for ($attempt = 0; ; $attempt++) {
            $this->config->assertReady();
            if (DB::transactionLevel() !== 0) {
                throw new NotionException('NOTION_TRANSACTION_OPEN');
            }
            ($this->heartbeat)();
            $wait = DB::transaction(function (): float {
                $state = DB::table('notion_sync_state')->where('id', 1)->lockForUpdate()->firstOrFail();
                $now = CarbonImmutable::now();
                $next = $state->next_request_at === null ? $now : CarbonImmutable::parse($state->next_request_at)->max($now);
                DB::table('notion_sync_state')->where('id', 1)->update([
                    'next_request_at' => $next->addMicroseconds((int) ceil(1000000 / config('v2_notion.requests_per_second')))->format('Y-m-d H:i:s.uP'),
                ]);

                return max(0, $now->diffInMicroseconds($next) / 1000000);
            });
            $this->clock->wait($wait, $this->heartbeat);
            $this->config->assertReady();
            ($this->heartbeat)();
            $response = null;
            try {
                $response = Http::withToken(config('v2_notion.token'))
                    ->withHeaders(['Notion-Version' => config('v2_notion.api_version')])
                    ->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(5)
                    ->timeout(config('v2_notion.timeout_seconds'))
                    ->send($method, 'https://api.notion.com:443/v1'.$path, $body === [] ? [] : ['json' => $body]);
            } catch (ConnectionException) {
            }
            ($this->heartbeat)();
            if ($response?->successful()) {
                $result = $response->json();
                if (! is_array($result)) {
                    throw new NotionException('NOTION_RESPONSE_INVALID');
                }

                return $result;
            }
            $status = $response?->status();
            $retryable = $status === null || in_array($status, [429, 529, 500, 502, 503, 504], true);
            if ($status === 429 && $response->json('additional_data.rate_limit_reason') === 'public_api_request_blocked') {
                $retryable = false;
            }
            $retryAfter = $response?->header('Retry-After') ?: $response?->json('additional_data.retry_after');
            $delay = (is_string($retryAfter) || is_int($retryAfter)) && preg_match('/\A[0-9]+\z/', (string) $retryAfter)
                ? max((float) $retryAfter, 2 ** $attempt) : 2 ** $attempt;
            if ($retryable) {
                DB::transaction(function () use ($delay): void {
                    $state = DB::table('notion_sync_state')->where('id', 1)->lockForUpdate()->firstOrFail();
                    $next = CarbonImmutable::now()->addSeconds($delay);
                    if ($state->next_request_at !== null) {
                        $next = $next->max(CarbonImmutable::parse($state->next_request_at));
                    }
                    DB::table('notion_sync_state')->where('id', 1)->update(['next_request_at' => $next->format('Y-m-d H:i:s.uP')]);
                });
            }
            if (! $retryable) {
                throw new NotionException(match ($status) {
                    401, 403 => 'NOTION_ACCESS_DENIED',
                    429, 529 => 'NOTION_RATE_LIMITED',
                    default => 'NOTION_REQUEST_FAILED',
                });
            }
            if ($pageId !== null && ($status === null || $status >= 500)) {
                $current = $this->page($pageId);
                if (($current['in_trash'] ?? true) !== false) {
                    throw new NotionException('NOTION_PAGE_UNAVAILABLE');
                }
                $matches = true;
                foreach ($body['properties'] as $name => $value) {
                    if (array_key_exists('number', $value)
                        && (! isset($current['properties'][$name]['number'])
                            || (float) $current['properties'][$name]['number'] !== (float) $value['number'])) {
                        $matches = false;
                    }
                }
                if ($matches) {
                    return $current;
                }
            }
            if ($attempt >= config('v2_notion.max_retries')) {
                throw new NotionException(in_array($status, [429, 529], true) ? 'NOTION_RATE_LIMITED' : 'NOTION_REQUEST_FAILED');
            }
        }
    }

    private function uuid(string $value): void
    {
        if (! Str::isUuid($value)) {
            throw new NotionException('NOTION_RESPONSE_INVALID');
        }
    }
}
