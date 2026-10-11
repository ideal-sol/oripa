<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use SensitiveParameter;

final class V2BannerImportCsv
{
    public const HEADERS = ['管理ID', 'タイトル', '画像URL', 'カテゴリ'];

    public function parse(#[SensitiveParameter] array $input): array
    {
        $required = ['file_name', 'content_base64', 'default_category_id'];
        if (array_diff($required, array_keys($input)) !== []
            || array_diff(array_keys($input), [...$required, 'refresh_existing_images']) !== []
            || ! is_string($input['file_name']) || trim($input['file_name']) === ''
            || mb_strlen($input['file_name']) > 191
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $input['file_name'])
            || ! is_string($input['content_base64']) || strlen($input['content_base64']) > 2796204
            || ! is_string($input['default_category_id'])
            || (array_key_exists('refresh_existing_images', $input) && ! is_bool($input['refresh_existing_images']))) {
            $this->invalid('CSV入力の形式が不正です。');
        }
        $content = base64_decode($input['content_base64'], true);
        if ($content === false || strlen($content) > 2097152 || preg_match('//u', $content) !== 1 || str_contains($content, "\0")) {
            $this->invalid('CSVはUTF-8、2MiB以下で指定してください。');
        }
        $sha = hash('sha256', $content);
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        $records = $this->records($content);
        $headers = array_shift($records)['values'] ?? [];
        $aliases = config('v2_banner_import.header_aliases', []);
        if (! is_array($aliases)) {
            $this->invalid('見出し別名の設定が不正です。');
        }
        foreach ($aliases as $alias => $canonical) {
            if (! is_string($alias) || trim($alias) !== $alias || $alias === ''
                || in_array($alias, self::HEADERS, true) || ! in_array($canonical, self::HEADERS, true)) {
                $this->invalid('見出し別名の設定が不正です。');
            }
        }
        $headers = array_map(static fn (string $header): string => $aliases[$header] ?? $header, $headers);
        if (array_diff(['管理ID', 'タイトル'], $headers) !== [] || count($headers) !== count(array_unique($headers, SORT_STRING))) {
            $this->invalid('必須見出しが不足しているか、見出しが重複しています。', 1);
        }
        $rows = [];
        foreach ($records as $record) {
            if (count($record['values']) !== count($headers)) {
                $this->invalid('見出しとデータの列数が一致しません。', $record['row']);
            }
            $rows[] = ['row' => $record['row'], 'values' => array_intersect_key(array_combine($headers, $record['values']), array_flip(self::HEADERS))];
        }
        if ($rows === []) {
            $this->invalid('データ行を指定してください。');
        }
        return ['sha256' => $sha, 'rows' => $rows];
    }

    private function records(#[SensitiveParameter] string $content): array
    {
        $records = [];
        $fields = [];
        $field = '';
        $state = 'start';
        $line = 1;
        $startLine = 1;
        $length = strlen($content);
        for ($offset = 0; $offset <= $length; $offset++) {
            $character = $offset === $length ? null : $content[$offset];
            if ($state === 'quoted') {
                if ($character === null) {
                    $this->invalid('引用符が閉じられていません。', $startLine);
                }
                if ($character === '"') {
                    if (($content[$offset + 1] ?? null) === '"') {
                        $field .= '"';
                        $offset++;
                    } else {
                        $state = 'closed';
                    }
                } else {
                    $field .= $character;
                    if ($character === "\n") {
                        $line++;
                    }
                }
                continue;
            }
            if ($character === ',' || $character === "\n" || $character === "\r" || $character === null) {
                $fields[] = trim($field);
                $field = '';
                $state = 'start';
                if ($character === ',') {
                    continue;
                }
                if ($character === "\r") {
                    if (($content[$offset + 1] ?? null) !== "\n") {
                        $this->invalid('改行はLFまたはCRLFを使用してください。', $line);
                    }
                    $offset++;
                }
                if ($startLine === 1 || array_filter($fields, static fn (string $value): bool => $value !== '') !== []) {
                    $records[] = ['row' => $startLine, 'values' => $fields];
                    if (count($records) > 1001) {
                        $this->invalid('CSVはデータ1,000行以下で指定してください。');
                    }
                }
                $fields = [];
                $startLine = ++$line;
            } elseif ($character === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($character === '"' || $state === 'closed') {
                $this->invalid('CSVの引用符または区切りの形式が不正です。', $line);
            } else {
                $field .= $character;
                $state = 'plain';
            }
        }
        return $records;
    }

    public function validation(array $errors): never
    {
        throw new V2CatalogException('CSV_VALIDATION_FAILED', 422, 'CSVを確認してください。', [
            'errors' => array_slice($errors, 0, 200), 'error_count' => count($errors),
        ]);
    }

    private function invalid(string $message, ?int $row = null): never
    {
        $this->validation([['row' => $row, 'column' => null, 'code' => 'CSV_INVALID', 'message' => $message]]);
    }
}
