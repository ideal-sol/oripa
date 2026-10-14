<?php

namespace App\Domain\Integrations\Notion;

use App\Support\V2ExternalId;
use Illuminate\Support\Str;

final class NotionSchema
{
    public function validate(array $schema): array
    {
        if (($schema['object'] ?? null) !== 'data_source' || ($schema['in_trash'] ?? true) !== false
            || ($schema['id'] ?? null) !== config('v2_notion.data_source_id')) {
            throw new NotionException('NOTION_SCHEMA_MISMATCH');
        }
        $mapping = [];
        $seen = [];
        foreach (config('v2_notion.properties') as $field => $name) {
            if ($name === null || $name === '') {
                continue;
            }
            $property = $schema['properties'][$name] ?? null;
            $types = match ($field) {
                'external_id' => ['title', 'rich_text', 'unique_id', 'formula'],
                'on_hand' => ['number', 'formula', 'rollup'],
                'available' => ['formula'],
                'synced_at' => ['date'],
                default => ['number'],
            };
            if (! is_array($property) || ! is_string($property['id'] ?? null)
                || ! in_array($property['type'] ?? null, $types, true) || in_array($property['id'], $seen, true)) {
                throw new NotionException('NOTION_SCHEMA_MISMATCH');
            }
            $seen[] = $property['id'];
            $mapping[$field] = ['name' => $name, 'id' => $property['id'], 'type' => $property['type']];
        }

        return $mapping;
    }

    public function page(array $page, array $mapping): ?array
    {
        if (($page['object'] ?? null) !== 'page' || ! is_string($page['id'] ?? null) || ! Str::isUuid($page['id'])
            || ($page['parent']['data_source_id'] ?? null) !== config('v2_notion.data_source_id')
            || ! is_bool($page['in_trash'] ?? null)) {
            throw new NotionException('NOTION_RESPONSE_INVALID');
        }
        if ($page['in_trash'] || ($page['archived'] ?? false)) {
            return null;
        }
        $values = [];
        foreach ($mapping as $field => $definition) {
            $property = $page['properties'][$definition['name']] ?? null;
            if (! is_array($property) || ($property['type'] ?? null) !== $definition['type']
                || ($property['id'] ?? null) !== $definition['id']) {
                throw new NotionException('NOTION_SCHEMA_MISMATCH');
            }
            $type = $property['type'];
            if ($type === 'formula' && ! in_array($property['formula']['type'] ?? null,
                [$field === 'external_id' ? 'string' : 'number'], true)) {
                throw new NotionException('NOTION_SCHEMA_MISMATCH');
            }
            $values[$field] = $field === 'external_id' ? $this->externalId($property) : $this->number($property);
        }

        return ['page_id' => $page['id'], 'external_id' => $values['external_id'],
            'on_hand' => $values['on_hand'], 'available' => $values['available'],
            'values' => array_intersect_key($values, array_flip(['required_quantity', 'allocation', 'stored', 'shipped']))];
    }

    private function externalId(array $property): ?string
    {
        $type = $property['type'];
        $value = null;
        if (in_array($type, ['title', 'rich_text'], true)) {
            $fragments = $property[$type] ?? null;
            if (! is_array($fragments)) {
                return null;
            }
            $value = '';
            foreach ($fragments as $fragment) {
                if (! is_string($fragment['plain_text'] ?? null)) {
                    return null;
                }
                $value .= $fragment['plain_text'];
            }
        } elseif ($type === 'unique_id') {
            $number = $property['unique_id']['number'] ?? null;
            $prefix = $property['unique_id']['prefix'] ?? null;
            if (is_int($number) && $number >= 0 && ($prefix === null || is_string($prefix))) {
                $value = ($prefix === null || $prefix === '' ? '' : $prefix.'-').$number;
            }
        } elseif ($type === 'formula') {
            $value = $property['formula']['string'] ?? null;
        }
        try {
            return is_string($value) ? V2ExternalId::normalize($value, new \InvalidArgumentException()) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function number(array $property): int|float|null
    {
        $type = $property['type'];
        $value = match ($type) {
            'number' => $property['number'] ?? null,
            'formula', 'rollup' => ($property[$type]['type'] ?? null) === 'number' ? ($property[$type]['number'] ?? null) : null,
            default => null,
        };

        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? $value : null;
    }
}
