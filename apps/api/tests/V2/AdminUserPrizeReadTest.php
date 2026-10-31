<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\PrizeShipping\Services\V2AdminUserPrizeReadService;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUserPrizeReadTest extends TestCase
{
    private User $user;

    /** @var list<string> */
    private array $prizeIds;

    private string $gachaCode;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-09-05T00:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_prize_shipping.address_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        ]);
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_all_shipping_management_roles_can_read_snapshot_and_fulfillment_detail(): void
    {
        $shipping = app(V2PrizeShippingService::class);
        $address = $shipping->createAddress($this->user, $this->address(), (string) Str::uuid7());
        $shipping->createShippingRequest(
            $this->user,
            $address['id'],
            [$this->prizeIds[0]],
            'admin-read-shipping-'.Str::uuid(),
            (string) Str::uuid7()
        );
        $shipping->exchange(
            $this->user,
            [$this->prizeIds[1]],
            'admin-read-exchange-'.Str::uuid(),
            (string) Str::uuid7()
        );

        foreach (V2AdminRole::cases() as $role) {
            Auth::forgetGuards();
            $client = $this->asAdmin($this->adminSession($role)['token']);
            $list = $client->getJson('/admin/api/v2/user-prizes?limit=20')
                ->assertOk()
                ->assertJsonCount(5, 'items')
                ->assertJsonPath('items.0.user.id', $this->user->public_id)
                ->assertJsonPath('items.0.user.display_name', '保有景品検証User')
                ->assertJsonPath('items.0.prize.name', 'Fixture S景品')
                ->assertJsonPath('items.0.prize.rank.name', 'Sランク')
                ->assertJsonPath('items.0.gacha.id', $this->gachaCode)
                ->assertJsonMissingPath('items.0.user.email');
            $this->assertPrivate($list);

            $detail = $client->getJson('/admin/api/v2/user-prizes/'.$this->prizeIds[0])
                ->assertOk()
                ->assertJsonPath('data.id', $this->prizeIds[0])
                ->assertJsonPath('data.status', 'shipping_requested')
                ->assertJsonPath('data.fulfillment.shipping_status', 'requested')
                ->assertJsonPath('data.shipping.shipping_address.city', '検証市')
                ->assertJsonPath('data.draw.executed_count', 5)
                ->assertJsonPath('data.allowed_actions.shipping.allowed', false)
                ->assertJsonMissingPath('data.user.email');
            $this->assertPrivate($detail);
        }
    }

    public function test_filters_and_cursor_use_canonical_backend_fields(): void
    {
        $client = $this->asAdmin($this->adminSession(V2AdminRole::Operator)['token']);
        $first = $client->getJson('/admin/api/v2/user-prizes?limit=2&user=保有景品検証&prize_name=Fixture&gacha='.urlencode($this->gachaCode).'&status=stored')
            ->assertOk()
            ->assertJsonCount(2, 'items');
        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);
        self::assertNotSame('', $cursor);
        self::assertFalse(ctype_digit($cursor));

        Auth::forgetGuards();
        $second = $this->asAdmin($this->adminSession(V2AdminRole::Owner)['token'])
            ->getJson('/admin/api/v2/user-prizes?limit=2&status=stored&cursor='.urlencode($cursor))
            ->assertOk();
        self::assertNotSame($first->json('items.0.id'), $second->json('items.0.id'));
    }

    public function test_unauthenticated_invalid_query_empty_and_not_found_fail_closed(): void
    {
        $this->getJson('/admin/api/v2/user-prizes')->assertUnauthorized();
        Auth::forgetGuards();
        $client = $this->asAdmin($this->adminSession(V2AdminRole::Owner)['token']);
        $client->getJson('/admin/api/v2/user-prizes?status=unknown')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ADMIN_USER_PRIZE_QUERY_INVALID');
        $client->getJson('/admin/api/v2/user-prizes?user=missing-user')
            ->assertOk()
            ->assertJsonCount(0, 'items');
        $client->getJson('/admin/api/v2/user-prizes/'.Str::uuid7())
            ->assertNotFound()
            ->assertJsonPath('code', 'ADMIN_USER_PRIZE_NOT_FOUND');
    }

    public function test_list_query_count_is_constant_and_does_not_expose_internal_ids(): void
    {
        $session = $this->adminSession(V2AdminRole::Owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $single = app(V2AdminUserPrizeReadService::class)->listing(
            $session['context'],
            ['limit' => 1]
        );
        $singleQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $result = app(V2AdminUserPrizeReadService::class)->listing(
            $session['context'],
            ['limit' => 20]
        );
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertCount(1, $single['items']);
        self::assertCount(5, $result['items']);
        self::assertSame($singleQueries, $queries);
        self::assertLessThanOrEqual(8, $queries);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['user_id', 'draw_result_id', 'gacha_version_prize_id', 'password_hash'] as $field) {
            self::assertStringNotContainsString('"'.$field.'"', $encoded);
        }
    }

    public function test_admin_shipping_list_detail_filters_and_selected_csv_use_canonical_records(): void
    {
        $shipping = app(V2PrizeShippingService::class);
        $firstAddress = $shipping->createAddress(
            $this->user,
            [...$this->address(), 'recipient_name' => '=Synthetic Recipient'],
            (string) Str::uuid7()
        );
        $first = $shipping->createShippingRequest(
            $this->user,
            $firstAddress['id'],
            [$this->prizeIds[0], $this->prizeIds[1]],
            'admin-shipping-first-'.Str::uuid(),
            (string) Str::uuid7()
        );
        DB::table('shipping_requests')->where('public_id', $first['id'])->update([
            'created_at' => '2026-09-04 03:00:00+00',
            'updated_at' => '2026-09-04 03:00:00+00',
        ]);
        $secondAddress = $shipping->createAddress(
            $this->user,
            $this->address(),
            (string) Str::uuid7()
        );
        $second = $shipping->createShippingRequest(
            $this->user,
            $secondAddress['id'],
            [$this->prizeIds[2]],
            'admin-shipping-second-'.Str::uuid(),
            (string) Str::uuid7()
        );

        $this->getJson('/admin/api/v2/shipping-requests')->assertUnauthorized();
        $session = $this->adminSession(V2AdminRole::Operator);
        $admin = Admin::query()->findOrFail($session['context']->adminId);
        $shipping->transitionShipping(
            $admin,
            $second['id'],
            'packing',
            null,
            null,
            'packing_started',
            (string) Str::uuid7()
        );
        Auth::forgetGuards();

        $client = $this->asAdmin($session['token']);
        $client->getJson('/admin/api/v2/shipping-requests')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $first['id'])
            ->assertJsonPath('items.0.user_id', $this->user->public_id)
            ->assertJsonPath('items.0.created_at', '2026-09-04T03:00:00+00:00');
        $client->getJson('/admin/api/v2/shipping-requests?status=packing')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $second['id']);
        $client->getJson('/admin/api/v2/shipping-requests?status=all&date_from=2026-09-04&date_to=2026-09-04')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $first['id']);
        $client->getJson('/admin/api/v2/shipping-requests?status=unknown')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ADMIN_SHIPPING_REQUEST_INVALID');
        $client->getJson('/admin/api/v2/shipping-requests/'.$first['id'])
            ->assertOk()
            ->assertJsonPath('user_id', $this->user->public_id)
            ->assertJsonPath('items.0.name', 'Fixture S景品')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('shipping_address.city', '検証市');

        $csv = $this->mutatingShippingRequest(
            $session['token'],
            '/admin/api/v2/shipping-requests/export',
            ['shipping_ids' => [$first['id']]]
        )->assertOk();
        $content = $csv->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = preg_split('/\r?\n/', trim($content));
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        $header = str_getcsv(substr($lines[0], 3));
        $row = str_getcsv($lines[1]);
        self::assertCount(9, $header);
        self::assertCount(9, $row);
        self::assertSame($first['id'], $row[6]);
        self::assertStringContainsString(' / ', $row[4]);
        self::assertStringContainsString(' / ', $row[7]);
        self::assertStringStartsWith("'=Synthetic", $row[0]);
        self::assertStringNotContainsString($second['id'], $content);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'shipping.csv_exported']);

        $this->mutatingShippingRequest(
            $session['token'],
            '/admin/api/v2/shipping-requests/export',
            ['shipping_ids' => [(string) Str::uuid7()]]
        )->assertNotFound();
    }

    public function test_b2_export_is_atomic_preserves_shipping_state_and_audits_counts_only(): void
    {
        $this->configureB2();
        CarbonImmutable::setTestNow('2026-10-31T15:01:00Z');
        $ids = $this->b2Requests();
        $session = $this->adminSession(V2AdminRole::Operator);
        $before = DB::table('shipping_requests')->whereIn('public_id', $ids)->orderBy('id')->get()->toJson();
        $histories = DB::table('shipping_request_status_histories')->count();
        $mail = DB::table('mail_deliveries')->count();
        \Illuminate\Support\Facades\Log::spy();
        $response = $this->mutatingShippingRequest($session['token'], '/admin/api/v2/shipping-requests/export-b2',
            ['shipping_ids' => [$ids[0], $ids[0], $ids[1]]])->assertOk()
            ->assertJsonPath('filename', 'b2cloud-shipping-20261101-0001.csv')
            ->assertJsonPath('warning_count', 0)->assertJsonPath('warnings', []);
        $this->assertPrivate($response);
        $csv = base64_decode($response->json('csv_base64'), true);
        self::assertIsString($csv);
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''),
            explode("\r\n", rtrim(mb_convert_encoding($csv, 'UTF-8', 'CP932'), "\r\n")));
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertCount(97, $row);
        }
        self::assertSame(str_replace('-', '', $ids[0]), $rows[1][0]);
        self::assertSame('計2点', $rows[1][29]);
        self::assertSame('計1点', $rows[2][29]);
        self::assertSame('トレーディングカード(置き配禁止)', $rows[1][27]);
        self::assertStringNotContainsString('Fixture', $csv);
        self::assertSame($before, DB::table('shipping_requests')->whereIn('public_id', $ids)->orderBy('id')->get()->toJson());
        self::assertSame($histories, DB::table('shipping_request_status_histories')->count());
        self::assertSame($mail, DB::table('mail_deliveries')->count());
        $audit = DB::table('audit_logs')->where('action_code', 'shipping.b2_csv_exported')->first();
        self::assertNotNull($audit);
        $metadata = json_decode($audit->metadata_redacted, true, flags: JSON_THROW_ON_ERROR);
        ksort($metadata);
        self::assertSame(['permission' => 'shipping.request.manage', 'shipping_count' => 2, 'warning_count' => 0], $metadata);
        $encodedAudit = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['検証用受取人', '検証町', '000-0000-0000', '架空発送元', '000000000001', 'csv_base64'] as $plain) {
            self::assertStringNotContainsString($plain, $encodedAudit);
        }
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            \Illuminate\Support\Facades\Log::shouldNotHaveReceived($level);
        }
        Auth::forgetGuards();
        // Same endpoint can return a direct CSV; neither mode streams before validation.
        $csrf = str_repeat('a', 64);
        $direct = $this->asAdmin($session['token'])->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf])
            ->json('POST', '/admin/api/v2/shipping-requests/export-b2', ['shipping_ids' => $ids], ['Accept' => 'text/csv'])
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=Windows-31J');
        self::assertSame($csv, $direct->getContent());
    }

    public function test_b2_invalid_selection_config_and_auth_fail_before_download(): void
    {
        $this->configureB2();
        $ids = $this->b2Requests();
        $session = $this->adminSession(V2AdminRole::Operator);
        foreach ([[], ['not-a-uuid'], array_map(fn () => (string) Str::uuid7(), range(1, 501))] as $selection) {
            Auth::forgetGuards();
            $this->mutatingShippingRequest($session['token'], '/admin/api/v2/shipping-requests/export-b2',
                ['shipping_ids' => $selection])->assertUnprocessable()->assertJsonPath('code', 'ADMIN_SHIPPING_REQUEST_INVALID');
        }
        Auth::forgetGuards();
        $this->mutatingShippingRequest($session['token'], '/admin/api/v2/shipping-requests/export-b2',
            ['shipping_ids' => [(string) Str::uuid7()]])->assertNotFound();
        foreach (['', 'invalid-setting-value'] as $value) {
            config(['v2_b2_shipping.billing_customer_code' => $value]);
            Auth::forgetGuards();
            $response = $this->mutatingShippingRequest($session['token'], '/admin/api/v2/shipping-requests/export-b2',
                ['shipping_ids' => $ids])->assertStatus(503)->assertJsonPath('code', 'B2_EXPORT_NOT_CONFIGURED');
            self::assertFalse($response->headers->has('Content-Disposition'));
            self::assertStringNotContainsString('invalid-setting-value', $response->getContent());
        }
        self::assertSame(0, DB::table('audit_logs')->where('action_code', 'shipping.b2_csv_exported')->count());
        Auth::forgetGuards();
        DB::table('admin_sessions')->where('admin_id', $session['context']->adminId)->update(['mfa_verified_at' => null]);
        $this->mutatingShippingRequest($session['token'], '/admin/api/v2/shipping-requests/export-b2',
            ['shipping_ids' => $ids])->assertUnauthorized();
        Auth::forgetGuards();
        Auth::guard('v2_admin')->setUser($this->user);
        $denied = app(\App\Http\Controllers\V2\V2AdminShippingController::class)
            ->exportB2(\Illuminate\Http\Request::create('/shipping-requests/export-b2', 'POST', ['shipping_ids' => $ids]));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('AUTHORIZATION_DENIED', $denied->getData(true)['code']);
    }

    public function test_b2_returns_every_warning_for_500_unique_shipping_ids_in_body(): void
    {
        $this->configureB2();
        $ids = $this->b2Requests();
        $source = (array) DB::table('shipping_requests')->where('public_id', $ids[0])->first();
        $idempotency = (array) DB::table('idempotency_records')->where('id', $source['idempotency_record_id'])->first();
        unset($idempotency['id']);
        unset($source['id']);
        $source['recipient_name_ciphertext'] = Crypt::encryptString(str_repeat('髙', 17));
        $source['street_ciphertext'] = Crypt::encryptString(str_repeat('あ', 33));
        $source['building_ciphertext'] = Crypt::encryptString(str_repeat('あ', 17));
        $source['phone_number_ciphertext'] = Crypt::encryptString('');
        $selection = [];
        foreach (range(1, 500) as $unused) {
            $source['public_id'] = (string) Str::uuid7();
            $idempotency['public_id'] = (string) Str::uuid7();
            $idempotency['key_hash'] = hash('sha256', $source['public_id']);
            $idempotency['resource_public_id'] = $source['public_id'];
            $source['idempotency_record_id'] = DB::table('idempotency_records')->insertGetId($idempotency);
            DB::table('shipping_requests')->insert($source);
            $selection[] = $source['public_id'];
        }
        $response = $this->mutatingShippingRequest($this->adminSession(V2AdminRole::Operator)['token'],
            '/admin/api/v2/shipping-requests/export-b2', ['shipping_ids' => $selection])->assertOk()
            ->assertJsonPath('warning_count', 500)->assertJsonCount(500, 'warnings');
        self::assertSame($selection, array_column($response->json('warnings'), 'shipping_id'));
        foreach ($response->json('warnings') as $warning) {
            self::assertSame(['shipping_id', 'codes'], array_keys($warning));
            self::assertSame(['ADDRESS_TOO_LONG', 'BUILDING_TOO_LONG', 'NAME_TOO_LONG', 'PHONE_INVALID', 'UNSUPPORTED_CHARACTER'], $warning['codes']);
        }
        self::assertLessThan(2000, strlen((string) $response->headers));
        self::assertStringNotContainsString('髙', json_encode($response->json('warnings'), JSON_UNESCAPED_UNICODE));
    }

    private function configureB2(): void
    {
        config(['v2_b2_shipping.sender_name' => '架空発送元', 'v2_b2_shipping.sender_phone' => '000-0000-0000',
            'v2_b2_shipping.sender_postal_code' => '0000000', 'v2_b2_shipping.sender_address' => '架空県架空市1-2-3',
            'v2_b2_shipping.billing_customer_code' => '000000000001', 'v2_b2_shipping.freight_number' => '01']);
    }

    private function b2Requests(): array
    {
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($this->user, $this->address(), (string) Str::uuid7());
        $ids = [];
        foreach ([[$this->prizeIds[0], $this->prizeIds[1]], [$this->prizeIds[2]]] as $prizes) {
            $ids[] = $service->createShippingRequest($this->user, $address['id'], $prizes,
                'b2-synthetic-'.Str::uuid(), (string) Str::uuid7())['id'];
        }

        return $ids;
    }

    private function createFixture(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['versions'][0]['allowed_draw_counts'] = [1, 5, 10];
        $fixture['versions'][0]['total_count'] = 1000;
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $index === 0 ? 100 : 900;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $email = 'admin-user-prize-'.Str::uuid().'@example.test';
        $this->user = User::query()->create([
            'display_name' => '保有景品検証User',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        DB::table('user_phone_numbers')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $this->user->getKey(),
            'phone_ciphertext' => Crypt::encryptString('+819012345678'),
            'phone_hmac' => hash('sha256', 'admin-user-prize-phone'),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(V2PointService::class)->grantFree(
            $this->user->id,
            100_000,
            'admin-user-prize-points-'.Str::uuid()
        );
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int =>
                    intdiv(5_000 * $maximum, 1_000_000) + 1
            )
        );
        app(V2DrawService::class)->create(
            $this->user,
            $fixture['gachas'][0]['public_id'],
            5,
            'admin-user-prize-draw-'.Str::uuid(),
            (string) Str::uuid7()
        );
        $this->prizeIds = DB::table('user_prizes')
            ->where('user_id', $this->user->id)
            ->orderBy('id')
            ->pluck('public_id')
            ->all();
        self::assertCount(5, $this->prizeIds);
        $this->gachaCode = (string) DB::table('catalog_gachas')
            ->where('public_id', $fixture['gachas'][0]['public_id'])
            ->value('public_code');
    }

    /** @return array{token: string, context: V2AdminAuthorizationContext} */
    private function adminSession(V2AdminRole $role): array
    {
        $email = 'user-prize-'.$role->value.'-'.Str::uuid().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $hash = app(V2SessionPolicy::class)->hashSessionId($token);
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(12),
        ]);

        return [
            'token' => $token,
            'context' => new V2AdminAuthorizationContext(
                (int) $admin->id,
                $admin->public_id,
                $role,
                $hash,
                hash('sha256', $hash),
                (string) Str::uuid7()
            ),
        ];
    }

    /** @return array<string, string|null> */
    private function address(): array
    {
        return [
            'recipient_name' => '検証用受取人',
            'postal_code' => '000-0000',
            'prefecture' => '検証県',
            'city' => '検証市',
            'street' => '検証町1-2-3',
            'building' => null,
            'phone_number' => '000-0000-0000',
        ];
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    /** @param array<string, mixed> $payload */
    private function mutatingShippingRequest(string $token, string $uri, array $payload)
    {
        $csrf = str_repeat('a', 64);

        return $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->postJson($uri, $payload);
    }

    private function assertPrivate(\Illuminate\Testing\TestResponse $response): void
    {
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }
}
