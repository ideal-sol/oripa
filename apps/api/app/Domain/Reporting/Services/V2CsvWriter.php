<?php

namespace App\Domain\Reporting\Services;

use RuntimeException;

final class V2CsvWriter
{
    /**
     * @param resource $stream
     * @param list<string> $headers
     * @param iterable<array<string, scalar|null>> $rows
     */
    public function write($stream, array $headers, iterable $rows): int
    {
        if (! is_resource($stream) || $headers === []) {
            throw new RuntimeException('CSV output is invalid.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers);
        $count = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $values[] = $this->safe($row[$header] ?? null);
            }
            fputcsv($stream, $values);
            $count++;
        }

        return $count;
    }

    private function safe(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;
        if (preg_match('/\A[=+\-@\t\r]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
