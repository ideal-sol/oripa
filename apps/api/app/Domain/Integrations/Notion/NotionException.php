<?php

namespace App\Domain\Integrations\Notion;

use RuntimeException;

final class NotionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 503)
    {
        parent::__construct('Notion同期を完了できませんでした。設定と同期状態を確認してください。');
    }
}
