<?php

namespace Tests\Feature;

use App\Logging\PaidyLogFormatter;
use App\Logging\PaidyLogRedactor;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

final class PaidyLogProtectionTest extends TestCase
{
    public function test_real_artisan_serve_streams_and_request_bytes(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, dirname(__DIR__).'/paidy_serve_probe.php']);
        $process->setTimeout(40);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('PAIDY_SERVE_LOG_PROTECTION_PASS', $process->getOutput());
    }

    public function test_redacts_paths_context_and_exceptions_without_mutating_record(): void
    {
        $token = str_repeat('synthetic_', 4);
        config(['v2_paidy.webhook_path_token' => $token]);
        $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Error,
            'failure '.$token, ['nested' => [$token => $token], 'exception' => new \RuntimeException('/webhooks/v2/paidy/'.$token)]);
        foreach ([new LineFormatter(), new JsonFormatter()] as $inner) {
            $formatter = new PaidyLogFormatter($inner);
            foreach ([$formatter->format($record), $formatter->formatBatch([$record])] as $output) {
                self::assertStringNotContainsString($token, json_encode($output));
                self::assertStringContainsString('REDACTED', json_encode($output));
            }
        }
        self::assertSame('failure '.$token, $record->message);
        self::assertSame('REDACTED', PaidyLogRedactor::redact(substr($token, 0, 15).'...', $token));
    }

    public function test_unknown_encoded_token_and_historical_json_urls_are_redacted(): void
    {
        $token = 'unknown_synthetic_token';
        $uri = '/webhooks/v2/paidy/'.$token;
        foreach ([$uri, json_encode(['uri' => $uri])] as $message) {
            self::assertStringNotContainsString($token, PaidyLogRedactor::redact($message));
        }
        self::assertSame('REDACTED', PaidyLogRedactor::redact($token, null, str_replace('unknown', '%75nknown', $uri)));
        foreach (['/webhooks/v2/univapay/unchanged', '/api/v2/payments', 'ordinary diagnostic'] as $message) {
            self::assertSame($message, PaidyLogRedactor::redact($message));
        }
    }

    public function test_real_single_and_emergency_loggers_protect_shared_context(): void
    {
        $token = str_repeat('fixture_', 5);
        $path = tempnam(sys_get_temp_dir(), 'paidy-log-');
        config(['v2_paidy.webhook_path_token' => $token, 'logging.channels.single.path' => $path,
            'logging.channels.emergency.path' => $path]);
        try {
            Log::forgetChannel('single');
            Log::shareContext(['nested' => ['token' => $token]]);
            Log::channel('single')->error('failure '.$token, ['exception' => new \RuntimeException($token)]);
            Log::channel('deliberately_missing')->error('fallback '.$token);
            $output = file_get_contents($path);
            self::assertStringContainsString('failure REDACTED', $output);
            self::assertStringContainsString('fallback REDACTED', $output);
            self::assertStringContainsString('Using emergency logger', $output);
            self::assertStringNotContainsString($token, $output);
        } finally {
            Log::flushSharedContext();
            unlink($path);
        }
    }
}
