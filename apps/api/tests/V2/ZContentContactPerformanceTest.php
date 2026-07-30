<?php

namespace Tests\V2;

use App\Domain\ContentContact\Services\V2ContactService;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\ContentContact\Services\V2ContentReadService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZContentContactPerformanceTest extends TestCase
{
    public function test_content_contact_first_pages_and_concurrent_submission_meet_threshold(): void
    {
        if (getenv('V2_CONTENT_CONTACT_PERFORMANCE_TEST') !== '1') {
            self::markTestSkipped('Content／Contact performance test is opt-in.');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Contact concurrency verification.');
        }
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_content_contact.contact_hmac_key' =>
                'base64:'.base64_encode(str_repeat('h', 32)),
            'v2_identity.rate_limits.contact_ip' => [5, 3600],
            'v2_identity.rate_limits.contact_email' => [3, 3600],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' =>
                'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
        $context = $this->context();
        $this->fixtures($context->adminId);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $read = app(V2ContentReadService::class);
        $admin = app(V2ContentContactAdminService::class);
        $measurements = [
            'banner_100' => $this->measure(
                fn (): array => $read->banners(),
                static fn (array $result): bool => count($result['items']) === 100
            ),
            'notice_10000_first_page' => $this->measure(
                fn (): array => $read->notices(null, 50),
                static fn (array $result): bool =>
                    count($result['items']) === 50 && $result['next_cursor'] !== null
            ),
            'static_page_100_versions' => $this->measure(
                fn (): array => $admin->contentDetail(
                    $context,
                    'static-page',
                    '00000000-0000-7000-9000-000000000001'
                ),
                static fn (array $result): bool => count($result['versions']) === 100
            ),
            'contact_100000_first_page' => $this->measure(
                fn (): array => $admin->contactList($context, null, 50),
                static fn (array $result): bool =>
                    count($result['items']) === 50 && $result['next_cursor'] !== null
            ),
        ];
        $concurrency = $this->concurrentContacts(10);
        foreach ($measurements as $name => $measurement) {
            self::assertLessThanOrEqual(
                1000.0,
                $measurement['p95_ms'],
                "{$name} p95 exceeded one second."
            );
        }
        self::assertSame(0, $concurrency['failures']);
        self::assertSame(0, $concurrency['unresolved_deadlocks']);
        self::assertSame(10, $concurrency['accepted']);
        fwrite(STDOUT, "\nMIG056_CONTENT_CONTACT_PERFORMANCE=".json_encode([
            'fixture_counts' => [
                'banners' => 100,
                'notices' => 10000,
                'static_page_versions' => 100,
                'contacts' => 100000,
            ],
            'measurements' => $measurements,
            'query_count_all_runs_with_audit' => $queries,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'concurrent_contact' => $concurrency,
            'unresolved_deadlocks' => 0,
            'n_plus_one_detected' => false,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @param callable(array<string, mixed>): bool $assertion
     * @return array{p50_ms: float, p95_ms: float}
     */
    private function measure(callable $operation, callable $assertion): array
    {
        $durations = [];
        for ($run = 0; $run < 5; $run++) {
            $started = hrtime(true);
            $result = $operation();
            $durations[] = (hrtime(true) - $started) / 1_000_000;
            self::assertTrue($assertion($result));
        }
        sort($durations);

        return [
            'p50_ms' => round($durations[2], 3),
            'p95_ms' => round($durations[4], 3),
        ];
    }

    /** @return array{accepted: int, failures: int, unresolved_deadlocks: int, p95_ms: float} */
    private function concurrentContacts(int $workers): array
    {
        $directory = sys_get_temp_dir().'/mig056-contact-'.getmypid();
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach (range(1, $workers) as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Contact worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $started = hrtime(true);
                try {
                    app(V2ContactService::class)->submit([
                        'name' => "Load {$worker}",
                        'email' => "load-{$worker}-".Str::uuid7().'@example.test',
                        'subject' => 'Concurrent contact',
                        'body' => 'Task-only performance fixture.',
                        'website' => '',
                    ], null, "192.0.2.{$worker}", (string) Str::uuid7());
                    $result = [
                        'ok' => true,
                        'duration_ms' => (hrtime(true) - $started) / 1_000_000,
                    ];
                } catch (\Throwable $exception) {
                    $result = ['ok' => false, 'class' => get_class($exception)];
                }
                file_put_contents(
                    "{$directory}/{$worker}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit($result['ok'] ? 0 : 1);
            }
            $children[$pid] = $worker;
        }
        DB::disconnect();
        DB::reconnect();
        $deadlocks = 0;
        foreach ($children as $pid => $_worker) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $deadlocks++;
            }
        }
        $durations = [];
        $failures = 0;
        foreach (range(1, $workers) as $worker) {
            $path = "{$directory}/{$worker}.json";
            $result = is_file($path)
                ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false];
            if ($result['ok'] ?? false) {
                $durations[] = (float) $result['duration_ms'];
            } else {
                $failures++;
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
        sort($durations);
        $index = max(0, (int) ceil(count($durations) * 0.95) - 1);

        return [
            'accepted' => count($durations),
            'failures' => $failures,
            'unresolved_deadlocks' => $deadlocks,
            'p95_ms' => $durations === [] ? 0.0 : round($durations[$index], 3),
        ];
    }

    private function fixtures(int $adminId): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO catalog_presentation_assets (
                public_id, storage_identifier, public_path, checksum_sha256,
                media_type, mime_type, byte_size, alt_text, is_public,
                created_at, updated_at
            ) VALUES (
                '00000000-0000-7000-8000-000000000001',
                'content/performance.png', '/assets/content/performance.png',
                repeat('a', 64), 'image', 'image/png', 128,
                'Performance image', true, now(), now()
            )
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO content_banners (public_id, code, status, created_at, updated_at)
            SELECT
                ('00000000-0000-7000-8100-' || lpad(to_hex(gs), 12, '0'))::uuid,
                'banner-' || gs, 'draft', now(), now()
            FROM generate_series(1, 100) AS gs
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO content_notices (public_id, slug, status, created_at, updated_at)
            SELECT
                ('00000000-0000-7000-8200-' || lpad(to_hex(gs), 12, '0'))::uuid,
                'notice-' || gs, 'draft', now(), now()
            FROM generate_series(1, 10000) AS gs
            SQL);
        DB::table('content_static_pages')->insert([
            'public_id' => '00000000-0000-7000-9000-000000000001',
            'slug' => 'performance-page',
            'is_legal' => false,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement(
            <<<'SQL'
                INSERT INTO content_versions (
                    public_id, banner_id, version_number, status, title,
                    link_url, sort_order, is_important, publish_start_at,
                    content_checksum, created_by_admin_id, published_by_admin_id,
                    published_at, created_at, updated_at
                )
                SELECT
                    ('00000000-0000-7000-8300-' || lpad(to_hex(id), 12, '0'))::uuid,
                    id, 1, 'draft', code, '/', id, false, now() - interval '1 day',
                    repeat('b', 64), ?, NULL, NULL, now(), now()
                FROM content_banners
                WHERE code LIKE 'banner-%'
                SQL,
            [$adminId]
        );
        DB::statement(
            <<<'SQL'
                INSERT INTO content_versions (
                    public_id, notice_id, version_number, status, title, summary,
                    body_html, sort_order, is_important, publish_start_at,
                    content_checksum, created_by_admin_id, published_by_admin_id,
                    published_at, created_at, updated_at
                )
                SELECT
                    ('00000000-0000-7000-8400-' || lpad(to_hex(id), 12, '0'))::uuid,
                    id, 1, 'published', slug, 'summary', '<p>body</p>',
                    0, false, now() - interval '1 day', repeat('c', 64),
                    ?, ?, now(), now(), now()
                FROM content_notices
                WHERE slug LIKE 'notice-%'
                SQL,
            [$adminId, $adminId]
        );
        $pageId = DB::table('content_static_pages')
            ->where('slug', 'performance-page')->value('id');
        DB::statement(
            <<<'SQL'
                INSERT INTO content_versions (
                    public_id, static_page_id, version_number, status, title,
                    body_html, sort_order, is_important, publish_start_at,
                    content_checksum, created_by_admin_id, published_by_admin_id,
                    published_at, created_at, updated_at
                )
                SELECT
                    ('00000000-0000-7000-8500-' || lpad(to_hex(gs), 12, '0'))::uuid,
                    ?, gs, CASE WHEN gs = 100 THEN 'published' ELSE 'draft' END,
                    'Version ' || gs, '<p>body</p>', 0, false,
                    now() - interval '1 day', repeat('d', 64), ?,
                    CASE WHEN gs = 100 THEN ?::bigint ELSE NULL::bigint END,
                    CASE WHEN gs = 100 THEN now() ELSE NULL END,
                    now(), now()
                FROM generate_series(1, 100) AS gs
                SQL,
            [$pageId, $adminId, $adminId]
        );
        DB::statement(<<<'SQL'
            INSERT INTO content_version_assets (
                content_version_id, presentation_asset_id, usage_type,
                sort_order, created_at, updated_at
            )
            SELECT cv.id, asset.id, 'image', 0, now(), now()
            FROM content_versions cv
            JOIN catalog_presentation_assets asset
              ON asset.public_id = '00000000-0000-7000-8000-000000000001'
            WHERE cv.banner_id IS NOT NULL
            SQL);
        DB::statement(
            <<<'SQL'
                UPDATE content_versions
                SET status = 'published',
                    published_by_admin_id = ?,
                    published_at = now(),
                    updated_at = now()
                WHERE banner_id IS NOT NULL
                SQL,
            [$adminId]
        );
        DB::statement(<<<'SQL'
            UPDATE content_banners p
            SET status = 'published', published_version_id = cv.id, updated_at = now()
            FROM content_versions cv
            WHERE cv.banner_id = p.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE content_notices p
            SET status = 'published', published_version_id = cv.id, updated_at = now()
            FROM content_versions cv
            WHERE cv.notice_id = p.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE content_static_pages p
            SET status = 'published', published_version_id = cv.id, updated_at = now()
            FROM content_versions cv
            WHERE cv.static_page_id = p.id AND cv.version_number = 100
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO contact_inquiries (
                public_id, receipt_code, name_ciphertext, email_ciphertext,
                subject_ciphertext, body_ciphertext, email_correlation_hash,
                status, received_at, retention_until, created_at, updated_at
            )
            SELECT
                ('00000000-0000-7000-8600-' || lpad(to_hex(gs), 12, '0'))::uuid,
                'PERF-' || lpad(gs::text, 20, '0'), 'ciphertext', 'ciphertext',
                'ciphertext', 'ciphertext', repeat('e', 64), 'new',
                now() - (gs % 86400) * interval '1 second',
                now() + interval '365 days', now(), now()
            FROM generate_series(1, 100000) AS gs
            SQL);
    }

    private function context(): V2AdminAuthorizationContext
    {
        $email = 'content-performance-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', random_bytes(32));
        $sessionNow = now()->startOfSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => $sessionNow,
            'requires_mfa_enrollment' => false,
            'created_at' => $sessionNow,
            'last_activity_at' => $sessionNow,
            'idle_expires_at' => $sessionNow->copy()->addMinutes(15),
            'absolute_expires_at' => $sessionNow->copy()->addHours(8),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)
                ->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
