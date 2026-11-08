<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

final class PaidyLogFormatter implements FormatterInterface
{
    public function __construct(private FormatterInterface $inner) {}

    public function format(LogRecord $record): mixed
    {
        return $this->redact($this->inner->format($record));
    }

    public function formatBatch(array $records): mixed
    {
        return $this->redact($this->inner->formatBatch($records));
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$this->redact($key)] = $this->redact($item);
            }
            return $result;
        }
        return is_string($value) ? PaidyLogRedactor::redact(
            $value, config('v2_paidy.webhook_path_token'), $_SERVER['REQUEST_URI'] ?? null,
        ) : $value;
    }
}
