<?php

namespace App\Domain\Integrations\Notion;

use Illuminate\Support\Str;

final class NotionConfig
{
    public function enabled(): bool
    {
        return config('v2_notion.enabled') === true;
    }

    public function configured(): bool
    {
        $token = config('v2_notion.token');
        $mapping = config('v2_notion.properties');
        if (! is_string($token) || $token === '' || preg_match('/[\s\x00-\x1f\x7f]/', $token)
            || ! is_string(config('v2_notion.data_source_id')) || ! Str::isUuid(config('v2_notion.data_source_id'))
            || config('v2_notion.api_version') !== '2026-03-11' || ! is_array($mapping)) {
            return false;
        }
        foreach (['external_id', 'on_hand', 'available', 'required_quantity', 'allocation', 'stored'] as $field) {
            if (! is_string($mapping[$field] ?? null) || trim($mapping[$field]) === '') {
                return false;
            }
        }
        $names = [];
        if (array_diff(array_keys($mapping), ['external_id', 'on_hand', 'available', 'required_quantity', 'allocation', 'stored', 'shipped', 'synced_at']) !== []) {
            return false;
        }
        foreach ($mapping as $name) {
            if ($name === null || $name === '') {
                continue;
            }
            if (! is_string($name) || strlen($name) > 200 || in_array($name, $names, true)) {
                return false;
            }
            $names[] = $name;
        }
        foreach (['sync_interval_minutes' => [1, 1440], 'max_retries' => [0, 5],
            'timeout_seconds' => [1, 60], 'lease_seconds' => [90, 3600], 'failure_backoff_seconds' => [30, 86400]] as $key => [$minimum, $maximum]) {
            $value = config('v2_notion.'.$key);
            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                return false;
            }
        }
        $rate = config('v2_notion.requests_per_second');

        return (is_int($rate) || is_float($rate)) && is_finite((float) $rate) && $rate > 0 && $rate <= 10;
    }

    public function assertReady(): void
    {
        if (! $this->enabled()) {
            throw new NotionException('NOTION_DISABLED', 409);
        }
        if (! $this->configured()) {
            throw new NotionException('NOTION_CONFIGURATION_INVALID');
        }
    }
}
