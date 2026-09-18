<?php

namespace Tests\V2;

use App\Domain\Audit\V2\Services\V2AuditChainVerifier;
use App\Domain\Audit\V2\Services\V2AuditDailyDigestService;
use App\Domain\Audit\V2\Services\V2AuditHasher;
use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2CoinExpiryPolicy;
use App\Domain\Point\Services\V2PointLedgerService;
use App\Domain\Point\Services\V2PointService;
use App\Domain\Point\Services\V2PointSnapshotService;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class DomainTimestampPersistenceTest extends TestCase
{
    private CarbonImmutable $instant;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('Asia/Tokyo', config('app.timezone'));
        self::assertSame('Asia/Tokyo', date_default_timezone_get());
        self::assertSame(DB::connection()->getConfig('timezone'), DB::selectOne('SHOW TIME ZONE')->TimeZone);
        DB::beginTransaction();
        $this->instant = CarbonImmutable::parse('2026-09-17T10:28:20+09:00');
        Carbon::setTestNow($this->instant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_audit_instants_business_date_chain_and_daily_digest_are_consistent(): void
    {
        $audit = app(V2AuditLogService::class);
        foreach ([$this->instant, $this->instant->utc(), '2026-09-17 10:28:20'] as $input) {
            $record = $audit->record('test.timestamp.audit', ['occurred_at' => $input]);
            $this->assertInstant('audit_logs', $record->id, 'occurred_at', $this->instant);
            self::assertSame('2026-09-17', $record->business_date->toDateString());
        }
        $midnight = $audit->record('test.timestamp.midnight', ['occurred_at' => '2026-09-18T00:00:00+09:00']);
        self::assertSame('2026-09-18', $midnight->business_date->toDateString());
        $this->assertInstant('audit_logs', $midnight->id, 'occurred_at', CarbonImmutable::parse('2026-09-17T15:00:00Z'));
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
        $digest = app(V2AuditDailyDigestService::class)->generate('2026-09-17');
        $this->assertInstant('audit_daily_digests', $digest->id, 'generated_at', $this->instant);
        self::assertSame(3, $digest->record_count);
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    public function test_point_grants_idempotency_cutoffs_and_snapshot_keep_the_same_instant(): void
    {
        $user = $this->user();
        $points = app(V2PointService::class);
        $operation = $points->grantFree($user->id, 100, 'timestamp-point-grant', $this->instant);
        self::assertSame($operation->id, $points->grantFree($user->id, 100, 'timestamp-point-grant', $this->instant->utc())->id);
        $this->assertInstant('users', $user->id, 'created_at', $this->instant);
        $this->assertInstant('point_operations', $operation->id, 'occurred_at', $this->instant);
        $lot = DB::table('point_lots')->where('grant_operation_id', $operation->id)->firstOrFail();
        $this->assertInstant('point_lots', $lot->id, 'granted_at', $this->instant);
        $this->assertInstant('point_lots', $lot->id, 'expire_at', app(V2CoinExpiryPolicy::class)->expiresAt($this->instant));
        $record = DB::table('idempotency_records')->where('resource_public_id', $operation->public_id)->firstOrFail();
        foreach (['created_at', 'completed_at'] as $column) {
            $this->assertInstant('idempotency_records', $record->id, $column, $this->instant);
        }
        $this->assertInstant('idempotency_records', $record->id, 'expires_at', $this->instant->addDay());
        $ledger = app(V2PointLedgerService::class);
        self::assertSame(0, $ledger->rebuild($user->id, $this->instant)['free']);
        self::assertSame(100, $ledger->rebuild($user->id, $this->instant->addSecond()->utc())['free']);
        $snapshot = app(V2PointSnapshotService::class)->generate('2026-09-17');
        $this->assertInstant('point_balance_snapshots', $snapshot->id, 'source_cutoff_at', CarbonImmutable::parse('2026-09-18T00:00:00+09:00'));
        $this->assertInstant('point_balance_snapshots', $snapshot->id, 'generated_at', $this->instant);
        self::assertSame($snapshot->checksum, app(V2PointSnapshotService::class)->generate('2026-09-17')->checksum);
    }

    public function test_outbox_available_and_lease_boundaries_preserve_exactly_once_ownership(): void
    {
        $outbox = app(V2OutboxService::class);
        $message = $outbox->enqueue('timestamp.fixture', 'fixture', null, 'timestamp.ready', ['fixture' => true], 'timestamp-outbox', $this->instant->addSecond()->utc());
        self::assertSame($message->id, $outbox->enqueue('timestamp.fixture', 'fixture', null, 'timestamp.ready', ['fixture' => true], 'timestamp-outbox', $this->instant->addSecond())->id);
        $this->assertInstant('outbox_messages', $message->id, 'created_at', $this->instant);
        $this->assertInstant('outbox_messages', $message->id, 'available_at', $this->instant->addSecond());
        self::assertCount(0, $outbox->claim('timestamp-worker-a', 1, 5, ['timestamp.fixture']));
        Carbon::setTestNow($this->instant->addSecond());
        self::assertCount(1, $outbox->claim('timestamp-worker-a', 1, 5, ['timestamp.fixture']));
        $this->assertInstant('outbox_messages', $message->id, 'locked_at', $this->instant->addSecond());
        $this->assertInstant('outbox_messages', $message->id, 'lease_expires_at', $this->instant->addSeconds(6));
        Carbon::setTestNow($this->instant->addSeconds(5));
        self::assertCount(0, $outbox->claim('timestamp-worker-b', 1, 5, ['timestamp.fixture']));
        Carbon::setTestNow($this->instant->addSeconds(6));
        self::assertCount(1, $outbox->claim('timestamp-worker-b', 1, 5, ['timestamp.fixture']));
        $outbox->markDelivered($message->public_id, 'timestamp-worker-b');
        $this->assertInstant('outbox_messages', $message->id, 'delivered_at', $this->instant->addSeconds(6));
        self::assertCount(0, $outbox->claim('timestamp-worker-b', 1, 5, ['timestamp.fixture']));
    }

    public function test_payment_provider_time_and_point_grants_are_not_reinterpreted(): void
    {
        $user = $this->user();
        $planId = DB::table('point_purchase_plans')->insertGetId(V2TimestampFixture::attributes([
            'public_id' => (string) Str::uuid7(), 'code' => 'timestamp-plan', 'version_no' => 1,
            'name' => 'Timestamp fixture', 'amount' => 1000, 'paid_point_amount' => 1000,
            'free_point_amount' => 100, 'currency' => 'JPY', 'status' => 'published',
            'published_at' => $this->instant, 'available_from' => $this->instant,
            'available_until' => $this->instant->addMinute(),
        ]));
        $payments = app(V2PaymentService::class);
        $payment = $payments->createPayment($user->id, $planId, 'fixture', 'timestamp-provider-payment', 'timestamp-payment');
        $event = $payments->recordVerifiedProviderEvent('fixture', 'timestamp-provider-event', 'payment.succeeded', '{}', [], $payment->id, null, $this->instant->utc());
        $grant = $payments->confirmSucceeded($event->id);
        self::assertSame($grant->id, $payments->confirmSucceeded($event->id)->id);
        foreach (['created_at', 'updated_at', 'succeeded_at', 'points_granted_at'] as $column) {
            $this->assertInstant('payments', $payment->id, $column, $this->instant);
        }
        foreach (['provider_occurred_at', 'received_at', 'signature_verified_at'] as $column) {
            $this->assertInstant('payment_provider_events', $event->id, $column, $this->instant);
        }
        foreach (DB::table('point_lots')->where('user_id', $user->id)->get() as $lot) {
            $this->assertInstant('point_lots', $lot->id, 'granted_at', $this->instant);
        }
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }

    public function test_catalog_import_draw_bulk_and_inventory_timestamps_keep_the_same_instant(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['gachas'][0]['sold_count'] = 0;
        $manifest['versions'][0]['publish_start_at'] = '2026-09-17 10:28:20';
        $importer = app(V2CatalogFixtureImporter::class);
        $import = $importer->import($manifest);
        self::assertTrue($importer->import($manifest)['replay']);
        $run = DB::table('catalog_import_runs')->where('public_id', $import['public_id'])->firstOrFail();
        $this->assertInstant('catalog_import_runs', $run->id, 'completed_at', $this->instant);
        foreach (['catalog_categories', 'catalog_tags', 'catalog_gachas', 'catalog_gacha_versions', 'catalog_prizes', 'catalog_ranks'] as $table) {
            $row = DB::table($table)->firstOrFail();
            $this->assertInstant($table, $row->id, 'created_at', $this->instant);
        }
        $version = DB::table('catalog_gacha_versions')->firstOrFail();
        $this->assertInstant('catalog_gacha_versions', $version->id, 'publish_start_at', $this->instant);
        $user = $this->user();
        app(V2PointService::class)->grantFree($user->id, 10000, 'timestamp-draw-points', $this->instant);
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(static fn (int $minimum, int $maximum): int => $minimum));
        $result = app(V2DrawService::class)->create($user, $manifest['gachas'][0]['public_id'], 1, 'timestamp-draw', (string) Str::uuid7());
        $request = DB::table('draw_requests')->where('public_id', $result['id'])->firstOrFail();
        $this->assertInstant('draw_requests', $request->id, 'completed_at', $this->instant);
        foreach (DB::table('draw_results')->where('draw_request_id', $request->id)->get() as $row) {
            $this->assertInstant('draw_results', $row->id, 'occurred_at', $this->instant);
        }
        $prize = DB::table('user_prizes')->where('user_id', $user->id)->firstOrFail();
        $this->assertInstant('user_prizes', $prize->id, 'acquired_at', $this->instant);
        $this->assertInstant('user_prizes', $prize->id, 'storage_expires_at', $this->instant->addDays((int) config('v2_prize_shipping.storage_days', 60)));
        $lot = DB::table('point_lots')->where('user_id', $user->id)->firstOrFail();
        $this->assertInstant('point_lots', $lot->id, 'updated_at', $this->instant);
        foreach (DB::table('prize_inventories')->where('awarded_count', '>', 0)->get() as $inventory) {
            $this->assertInstant('prize_inventories', $inventory->id, 'updated_at', $this->instant);
        }
    }

    public function test_import_keeps_existing_postgres_fractional_second_rounding(): void
    {
        foreach (['2026-09-17T10:28:20.600000+09:00', '2026-09-17T01:28:20.600000Z'] as $input) {
            DB::beginTransaction();
            try {
                $manifest = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
                $manifest['versions'][0]['publish_start_at'] = $input;
                app(V2CatalogFixtureImporter::class)->import($manifest);
                $version = DB::table('catalog_gacha_versions')->firstOrFail();
                $this->assertInstant('catalog_gacha_versions', $version->id, 'publish_start_at', $this->instant->addSecond());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_new_writes_do_not_repair_legacy_timestamps_or_audit_signatures(): void
    {
        $legacyInstant = $this->instant->addHours(9);
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update(['created_at' => V2DatabaseTimestamp::format($legacyInstant)]);
        $user->refresh()->forceFill(['display_name' => 'Unrelated update'])->save();
        $this->assertInstant('users', $user->id, 'created_at', $legacyInstant);
        $this->assertInstant('users', $user->id, 'updated_at', $this->instant);

        $audit = app(V2AuditLogService::class);
        $previous = $audit->record('test.timestamp.before_legacy');
        $attributes = $previous->getAttributes();
        unset($attributes['id']);
        $attributes['public_id'] = (string) Str::uuid7();
        $attributes['previous_hash'] = $previous->record_hash;
        $attributes['metadata_redacted'] = $previous->metadata_redacted;
        $attributes['record_hash'] = app(V2AuditHasher::class)->digest($audit->hashPayload($attributes), $previous->hmac_key_version);
        $attributes['metadata_redacted'] = json_encode($attributes['metadata_redacted'], JSON_THROW_ON_ERROR);
        $attributes['occurred_at'] = V2DatabaseTimestamp::format($legacyInstant);
        $legacyId = DB::table('audit_logs')->insertGetId($attributes);
        $current = $audit->record('test.timestamp.after_legacy');
        self::assertSame($attributes['record_hash'], $current->previous_hash);
        self::assertSame($attributes['record_hash'], DB::table('audit_logs')->where('id', $legacyId)->value('record_hash'));
        $this->assertInstant('audit_logs', $legacyId, 'occurred_at', $legacyInstant);
        $this->assertInstant('audit_logs', $current->id, 'occurred_at', $this->instant);
        self::assertFalse(app(V2AuditChainVerifier::class)->verify());
    }

    private function user(): User
    {
        $email = Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Timestamp fixture', 'email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => $this->instant->utc(), 'state' => V2UserState::Active,
            'password_hash' => app(V2PasswordPolicy::class)->hash('timestamp fixture password'),
        ]);
    }

    private function assertInstant(string $table, int $identifier, string $column, DateTimeInterface $expected): void
    {
        $epoch = DB::table($table)->where('id', $identifier)->value(DB::raw('extract(epoch from "'.$column.'")'));
        self::assertNotNull($epoch, $table.'.'.$column);
        self::assertSame($expected->getTimestamp(), (int) $epoch, $table.'.'.$column);
    }
}
