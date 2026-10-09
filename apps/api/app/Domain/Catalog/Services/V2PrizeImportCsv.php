<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;

final class V2PrizeImportCsv
{
    public const HEADERS = ['管理ID', 'ランク', '枚数', '交換ポイント', '原価', '発送のみ', '表示順', 'カード名'];

    public function parse(array $input, bool $apply): array
    {
        $required = ['file_name', 'content_base64', 'expected_version_revision', ...($apply ? ['plan_checksum'] : [])];
        if (array_diff($required, array_keys($input)) !== [] || array_diff(array_keys($input), $required) !== []
            || ! is_string($input['file_name']) || trim($input['file_name']) === '' || strlen($input['file_name']) > 191
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $input['file_name'])
            || ! is_int($input['expected_version_revision']) || $input['expected_version_revision'] < 1
            || ! is_string($input['content_base64']) || strlen($input['content_base64']) > 1398104
            || ($apply && (! is_string($input['plan_checksum']) || ! preg_match('/\A[0-9a-f]{64}\z/', $input['plan_checksum'])))) {
            $this->invalid('ファイル名、CSVまたはリビジョンの形式が不正です。');
        }
        $content = base64_decode($input['content_base64'], true);
        if ($content === false || strlen($content) > 1048576 || preg_match('//u', $content) !== 1 || str_contains($content, "\0")) {
            $this->invalid('CSVはUTF-8、1MiB以下で指定してください。');
        }
        $sha = hash('sha256', $content);
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        $records = $this->records($content);
        $headers = array_shift($records)['values'] ?? [];
        if (array_diff(array_slice(self::HEADERS, 0, 5), $headers) !== [] || count($headers) !== count(array_unique($headers, SORT_STRING))) {
            $this->invalid('必須見出しが不足しているか、見出しが重複しています。', 1);
        }
        $rows = [];
        foreach ($records as $record) {
            if (count($record['values']) !== count($headers)) {
                $this->invalid('見出しとデータの列数が一致しません。', $record['row']);
            }
            $rows[] = ['row' => $record['row'], 'values' => array_intersect_key(array_combine($headers, $record['values']), array_flip(self::HEADERS))];
        }

        return ['file_name' => trim($input['file_name']), 'sha256' => $sha, 'headers' => array_values(array_intersect(self::HEADERS, $headers)), 'rows' => $rows];
    }

    private function records(string $content): array
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

    private function invalid(string $message, ?int $row = null): never
    {
        throw new V2CatalogException('CSV_VALIDATION_FAILED', 422, 'CSVを確認してください。', [
            'errors' => [['row' => $row, 'column' => null, 'code' => 'CSV_INVALID', 'message' => $message]], 'error_count' => 1,
        ]);
    }
}
