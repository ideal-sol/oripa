<?php

namespace App\Domain\Line\Services;

use InvalidArgumentException;
use SensitiveParameter;

final class V2LineDeliveryMessages
{
    /** Internal D5 contract: media URLs must resolve to immutable, server-verified assets. */
    public function validate(#[SensitiveParameter] array $messages): void
    {
        if (! array_is_list($messages) || count($messages) < 1 || count($messages) > 5) $this->invalid();
        foreach ($messages as $m) {
            if (! is_array($m)) $this->invalid();
            $keys = match ($m['type'] ?? null) {
                'text' => ['type', 'text'],
                'image' => ['type', 'originalContentUrl', 'previewImageUrl'],
                'video' => ['type', 'originalContentUrl', 'previewImageUrl'],
                'imagemap' => ['type', 'baseUrl', 'altText', 'height', 'layout', 'links'],
                'image_carousel' => ['type', 'altText', 'columns'],
                default => $this->invalid(),
            };
            if (array_diff(array_keys($m), $keys) || array_diff($keys, array_keys($m))) $this->invalid();
            foreach (['text' => 5000, 'altText' => 1500] as $key => $limit) {
                if (isset($m[$key])) $this->text($m[$key], $limit);
            }
            foreach (['originalContentUrl', 'previewImageUrl', 'baseUrl'] as $key) {
                if (isset($m[$key])) $this->url($m[$key]);
            }
            if ($m['type'] === 'imagemap') {
                if (! is_int($m['height']) || $m['height'] < 2 || $m['height'] > 10000) $this->invalid();
                $count = match ($m['layout']) { 'full' => 1, 'horizontal', 'vertical' => 2, 'grid' => 4, default => $this->invalid() };
                if (! is_array($m['links']) || ! array_is_list($m['links']) || count($m['links']) !== $count) $this->invalid();
                foreach ($m['links'] as $url) $this->url($url, 1000);
            }
            if ($m['type'] === 'image_carousel') {
                if (! is_array($m['columns']) || ! array_is_list($m['columns']) || count($m['columns']) < 1 || count($m['columns']) > 10) $this->invalid();
                foreach ($m['columns'] as $column) {
                    if (! is_array($column) || array_diff(array_keys($column), ['imageUrl', 'uri', 'label']) || ! isset($column['imageUrl'], $column['uri'])) $this->invalid();
                    $this->url($column['imageUrl']); $this->url($column['uri'], 1000);
                    if (isset($column['label'])) {
                        $this->text($column['label'], 20);
                        if (preg_match('/%[a-zA-Z0-9_]+%/', $column['label'])) $this->invalid();
                    }
                }
            }
        }
    }

    public function personalized(#[SensitiveParameter] array $messages): bool
    {
        $this->validate($messages);
        foreach ($messages as $message) {
            foreach (['text', 'altText'] as $key) {
                if (isset($message[$key]) && preg_match('/%(username|rank|points)%/', $message[$key])) return true;
            }
        }
        return false;
    }

    /** Called once, before durable encryption. Never used to rebuild a retry. */
    public function render(#[SensitiveParameter] array $messages, #[SensitiveParameter] array $values = []): array
    {
        $this->validate($messages);
        $replace = ['%username%' => ($values['username'] ?? '') !== '' ? $values['username'] : 'お客様',
            '%rank%' => $values['rank'] ?? '', '%points%' => number_format($values['points'] ?? 0)];
        $result = [];
        foreach ($messages as $m) {
            foreach (['text' => 5000, 'altText' => 1500] as $key => $limit) {
                if (isset($m[$key])) {
                    $m[$key] = $this->truncate(strtr($m[$key], $replace), $limit);
                    if (trim($m[$key]) === '') $this->invalid();
                }
            }
            if ($m['type'] !== 'text') app(V2LineDeliveryMedia::class)->assertVerified($m);
            if ($m['type'] === 'imagemap') {
                $height = $m['height']; $half = intdiv($height, 2);
                $areas = match ($m['layout']) {
                    'full' => [[0, 0, 1040, $height]],
                    'horizontal' => [[0, 0, 1040, $half], [0, $half, 1040, $height - $half]],
                    'vertical' => [[0, 0, 520, $height], [520, 0, 520, $height]],
                    'grid' => [[0, 0, 520, $half], [520, 0, 520, $half], [0, $half, 520, $height - $half], [520, $half, 520, $height - $half]],
                };
                $m['baseSize'] = ['width' => 1040, 'height' => $height];
                $m['actions'] = [];
                foreach ($areas as $i => $area) $m['actions'][] = ['type' => 'uri', 'linkUri' => $m['links'][$i], 'area' => array_combine(['x', 'y', 'width', 'height'], $area)];
                unset($m['height'], $m['layout'], $m['links']);
            } elseif ($m['type'] === 'image_carousel') {
                $columns = [];
                foreach ($m['columns'] as $column) $columns[] = ['imageUrl' => $column['imageUrl'], 'action' => ['type' => 'uri', 'uri' => $column['uri'], ...isset($column['label']) ? ['label' => $column['label']] : []]];
                $m = ['type' => 'template', 'altText' => $m['altText'], 'template' => ['type' => 'image_carousel', 'columns' => $columns]];
            }
            $result[] = $m;
        }
        return $result;
    }

    private function text(mixed $value, int $limit): void
    {
        if (! is_string($value) || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8') || $this->units($value) > $limit) $this->invalid();
        preg_match_all('/%([a-zA-Z0-9_]+)%/', $value, $matches);
        if (array_diff($matches[1], ['username', 'rank', 'points'])) $this->invalid();
    }

    private function url(mixed $value, int $limit = 2000): void
    {
        if (! is_string($value) || strlen($value) > $limit || filter_var($value, FILTER_VALIDATE_URL) === false
            || parse_url($value, PHP_URL_SCHEME) !== 'https') $this->invalid();
        preg_match_all('/%([a-zA-Z_][a-zA-Z0-9_]*)%/', $value, $matches);
        foreach ($matches[1] as $tag) if (strlen($tag) !== 2 || ! ctype_xdigit($tag)) $this->invalid();
    }

    private function units(string $text): int { return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2); }

    private function truncate(string $text, int $limit): string
    {
        $out = ''; $length = 0;
        foreach (mb_str_split($text) as $char) {
            $length += $this->units($char);
            if ($length > $limit) break;
            $out .= $char;
        }
        return $out;
    }

    private function invalid(): never { throw new InvalidArgumentException('Invalid LINE delivery message.'); }
}
