<?php

namespace Tests\V2;

use App\Domain\Line\Services\V2LineFriendIdBackfill;
use App\Domain\Line\Services\V2LineSubjectVault;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Committed synthetic fixture: HTTP must run outside a database transaction. */
final class LineFriendIdBackfillTest extends TestCase
{
    private int $bot;
    private string $actor;
    private string $subject;
    private string $schema;
    private array $connection;
    private array $financial;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(app()->environment('testing'));
        $this->schema = 'd7_test_'.bin2hex(random_bytes(6));
        $this->connection = config('database.connections.pgsql');
        DB::statement('CREATE SCHEMA '.$this->schema);
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations-v2', '--force' => true]));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cache.default' => 'array',
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        Http::preventStrayRequests();
        DB::table('line_bots')->where('is_active', true)->update(['is_active' => false]);
        $this->actor = (string) Str::uuid();
        $this->subject = 'U'.str_replace('-', '', (string) Str::uuid());
        $this->bot = DB::table('line_bots')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic D7',
            'channel_id' => (string) random_int(10000000000, 99999999999), 'channel_secret_ciphertext' => Crypt::encryptString('synthetic-only'),
            'is_active' => true, 'connection_status' => 'succeeded', 'connected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->financial = $this->financialCounts();
    }

    protected function tearDown(): void
    {
        self::assertSame($this->financial, $this->financialCounts());
        while (DB::transactionLevel() > 0) DB::rollBack();
        DB::purge('pgsql');
        config(['database.connections.pgsql' => $this->connection]);
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function financialCounts(): array
    {
        return array_map(fn ($table) => DB::table($table)->count(), ['point_operations', 'point_ledger_entries', 'point_lots', 'wallets']);
    }

    private function fake(array $pages): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        $sequence = Http::sequence();
        foreach ($pages as [$body, $status]) $sequence->push($body, $status);
        Http::fake(['https://api.line.me/oauth2/v3/token' => Http::response(['access_token' => 'synthetic-access', 'expires_in' => 900]),
            'https://api.line.me/v2/bot/followers/ids*' => $sequence]);
    }

    public function test_all_pages_short_page_next_duplicates_encryption_and_rerun(): void
    {
        $this->fake([[['userIds' => [$this->subject], 'next' => 'synthetic-next'], 200], [['userIds' => [$this->subject]], 200]]);
        $result = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('completed', $result['state']);
        self::assertSame(2, $result['pages']);
        self::assertSame(2, $result['fetched']);
        self::assertSame(1, $result['duplicates']);
        self::assertSame(1, $result['created']);
        $row = DB::table('line_recovered_friends')->where('line_bot_id', $this->bot)->sole();
        self::assertSame($this->subject, Crypt::decryptString($row->subject_ciphertext));
        self::assertContains($row->subject_hash, app(V2LineSubjectVault::class)->hashes($this->subject));
        self::assertSame(0, DB::table('line_friend_id_staging')->where('run_id', $result['run'])->count());
        $this->fake([[['userIds' => [$this->subject]], 200]]);
        $again = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('completed', $again['state']);
        self::assertSame(0, $again['created']);
        self::assertSame($row->subject_ciphertext, DB::table('line_recovered_friends')->where('id', $row->id)->value('subject_ciphertext'));
        $audit = json_encode(DB::table('audit_logs')->where('target_public_id', $result['run'])->get());
        foreach ([$this->subject, 'synthetic-next', 'synthetic-access', $row->subject_ciphertext] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $audit);
            self::assertStringNotContainsString($sensitive, json_encode($result));
        }
        self::assertSame(0, DB::table('line_friendships')->where('line_bot_id', $this->bot)->count());
        self::assertSame(0, DB::table('line_pending_follows')->where('line_bot_id', $this->bot)->count());
    }

    public static function errors(): array
    {
        return array_map(fn ($status) => [$status], [400, 401, 403, 429, 500, 503]);
    }

    #[DataProvider('errors')]
    public function test_later_http_error_never_formally_applies(int $status): void
    {
        $this->fake([[['userIds' => [$this->subject], 'next' => 'next'], 200], [['message' => 'sensitive-provider-body'], $status]]);
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('failed', $r['state']);
        self::assertSame($status, $r['http_status']);
        $this->assertNoWrites($r);
    }

    public static function malformed(): array
    {
        return [[['userIds' => [], 'next' => '']], [['userIds' => [], 'next' => null]],
            [['userIds' => [], 'next' => ['invalid']]], [['userIds' => 'invalid']], [['unknown' => []]],
            [['userIds' => ['invalid']]], [['userIds' => [], 'next' => "bad\nvalue"]]];
    }

    #[DataProvider('malformed')]
    public function test_unknown_response_fails_closed(array $body): void
    {
        $this->fake([[$body, 200]]);
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('failed', $r['state']);
        $this->assertNoWrites($r);
    }

    public function test_cycle_discards_staging(): void
    {
        $page = [['userIds' => [$this->subject], 'next' => 'cycle'], 200];
        $this->fake([$page, $page]);
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('failed', $r['state']);
        $this->assertNoWrites($r);
    }

    public function test_timeout_after_first_page_discards_staging(): void
    {
        $n = 0;
        Http::fake(function ($request) use (&$n) {
            if (str_contains($request->url(), '/token')) return Http::response(['access_token' => 'synthetic', 'expires_in' => 900]);
            self::assertSame(0, DB::transactionLevel());
            self::assertSame(0, DB::table('line_recovered_friends')->where('line_bot_id', $this->bot)->count());
            if (++$n === 2) throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout');
            return Http::response(['userIds' => [$this->subject], 'next' => 'next']);
        });
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('failed', $r['state']);
        $this->assertNoWrites($r);
    }

    public function test_bot_generation_change_discards_response(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/token')) return Http::response(['access_token' => 'synthetic', 'expires_in' => 900]);
            DB::table('line_bots')->where('id', $this->bot)->update(['authentication_revision' => DB::raw('authentication_revision + 1'), 'revision' => DB::raw('revision + 1')]);
            return Http::response(['userIds' => [$this->subject]]);
        });
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('failed', $r['state']);
        $this->assertNoWrites($r);
    }

    public function test_abandoned_fetch_is_cleaned_without_http_or_formal_write(): void
    {
        $id = $this->stage('fetching');
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor, null, true);
        self::assertSame('completed', $r['state']);
        self::assertSame('expired', DB::table('line_friend_id_runs')->where('id', $id)->value('state'));
        $this->assertNoWrites(['run' => $id]);
        Http::assertNothingSent();
    }

    public function test_resume_ready_run_preserves_observation_time_without_http(): void
    {
        $id = $this->stage('ready');
        $time = DB::table('line_friend_id_staging')->where('run_id', $id)->value('observed_at_ms');
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor, $id);
        self::assertSame('completed', $r['state']);
        self::assertSame((int) $time, (int) DB::table('line_recovered_friends')->where('line_bot_id', $this->bot)->value('observed_at_ms'));
        Http::assertNothingSent();
    }

    private function stage(string $state): string
    {
        $id = (string) Str::uuid();
        $revision = DB::table('line_bots')->where('id', $this->bot)->value('authentication_revision');
        DB::table('line_friend_id_runs')->insert(['id' => $id, 'actor_public_id' => $this->actor, 'line_bot_id' => $this->bot,
            'authentication_revision' => $revision, 'state' => 'fetching', 'created_at' => now(), 'expires_at' => now()->addDay(), 'fetched' => 1, 'unique_count' => 1]);
        DB::table('line_friend_id_staging')->insert(['run_id' => $id, 'subject_hash' => app(V2LineSubjectVault::class)->hashes($this->subject)[0],
            'subject_ciphertext' => Crypt::encryptString($this->subject), 'observed_at_ms' => now()->subHour()->getTimestampMs()]);
        if ($state !== 'fetching') DB::table('line_friend_id_runs')->where('id', $id)->update(['state' => $state]);
        return $id;
    }

    public function test_partial_application_resumes_exactly_once(): void
    {
        $second = 'U'.str_repeat('b', 32);
        $this->fake([[['userIds' => [$this->subject, $second]], 200]]);
        $insertions = 0;
        DB::listen(function ($event) use (&$insertions): void {
            if (str_starts_with($event->sql, 'insert into "line_recovered_friends"') && ++$insertions === 2) throw new \RuntimeException('Synthetic interruption');
        });
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('partial', $r['result']);
        self::assertSame(1, $r['applied']);
        self::assertSame(1, DB::table('line_friend_id_staging')->where('run_id', $r['run'])->count());
        $again = app(V2LineFriendIdBackfill::class)->execute($this->actor, $r['run']);
        self::assertSame('completed', $again['state']);
        self::assertSame(2, $again['applied']);
        self::assertSame(2, $again['created']);
    }

    public function test_old_hmac_identity_is_backfilled_and_ciphertext_not_overwritten(): void
    {
        $vault = app(V2LineSubjectVault::class);
        $old = $vault->hashes($this->subject)[0];
        $email = Str::uuid().'@example.test';
        $user = \App\Models\V2\User::query()->create(['email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password_hash' => '$argon2id$synthetic', 'password_login_enabled' => true, 'state' => 'active']);
        $account = \App\Models\V2\ExternalIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => 'line',
            'issuer' => 'https://access.line.me', 'subject_hash' => $old, 'linked_at' => now(), 'last_authenticated_at' => now()]);
        config(['v2_identity.sms_verification.phone_hmac_previous_keys' => ['base64:'.base64_encode(str_repeat('p', 32))],
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        $this->fake([[['userIds' => [$this->subject]], 200]]);
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('completed', $r['state']);
        self::assertSame(1, $r['filled']);
        self::assertSame($old, DB::table('line_recovered_friends')->where('line_bot_id', $this->bot)->value('subject_hash'));
        $ciphertext = DB::table('external_identity_accounts')->where('id', $account->id)->value('subject_ciphertext');
        self::assertSame($this->subject, Crypt::decryptString($ciphertext));
        $this->fake([[['userIds' => [$this->subject]], 200]]);
        self::assertSame('completed', app(V2LineFriendIdBackfill::class)->execute($this->actor)['state']);
        self::assertSame($ciphertext, DB::table('external_identity_accounts')->where('id', $account->id)->value('subject_ciphertext'));
    }

    public function test_resume_rejects_changed_bot_generation(): void
    {
        $id = $this->stage('ready');
        DB::table('line_bots')->where('id', $this->bot)->update(['authentication_revision' => DB::raw('authentication_revision + 1'), 'revision' => DB::raw('revision + 1')]);
        self::assertSame('failed', app(V2LineFriendIdBackfill::class)->execute($this->actor, $id)['state']);
        self::assertSame(0, DB::table('line_recovered_friends')->count());
        Http::assertNothingSent();
    }

    public function test_acquisition_deadline_discards_all_staging(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/token')) return Http::response(['access_token' => 'synthetic', 'expires_in' => 900]);
            \Carbon\Carbon::setTestNow(now()->addDay());
            return Http::response(['userIds' => [$this->subject], 'next' => 'expired']);
        });
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertSame('failed', $r['state']);
        $this->assertNoWrites($r);
    }

    public function test_command_requires_explicit_operator_and_human_flags(): void
    {
        $this->artisan('v2:line:backfill-friend-ids')->assertExitCode(2);
        Http::assertNothingSent();
        self::assertSame(0, DB::table('line_friend_id_runs')->count());
    }

    public function test_second_database_connection_excludes_concurrent_invocation(): void
    {
        config(['database.connections.d7_competing' => config('database.connections.pgsql')]);
        $other = DB::connection('d7_competing');
        $other->select('SELECT pg_advisory_lock(741107116)');
        try {
            self::assertSame('already_running', app(V2LineFriendIdBackfill::class)->execute($this->actor)['reason']);
            Http::assertNothingSent();
            self::assertSame(0, DB::table('line_friend_id_runs')->count());
        } finally {
            $other->select('SELECT pg_advisory_unlock(741107116)');
            DB::purge('d7_competing');
        }
    }

    public function test_bot_change_after_one_commit_reports_partial_and_rejects_resume(): void
    {
        $this->fake([[['userIds' => [$this->subject, 'U'.str_repeat('c', 32)]], 200]]);
        $changed = false;
        DB::listen(function ($event) use (&$changed): void {
            if (! $changed && str_starts_with($event->sql, 'update "line_friend_id_runs"') && str_contains($event->sql, 'applied + 1')) {
                $changed = true;
                DB::table('line_bots')->where('id', $this->bot)->update(['authentication_revision' => DB::raw('authentication_revision + 1'), 'revision' => DB::raw('revision + 1')]);
            }
        });
        $r = app(V2LineFriendIdBackfill::class)->execute($this->actor);
        self::assertTrue($changed);
        self::assertSame('partial', $r['result']);
        self::assertSame(1, $r['applied']);
        self::assertSame('failed', app(V2LineFriendIdBackfill::class)->execute($this->actor, $r['run'])['state']);
        self::assertSame(1, DB::table('line_recovered_friends')->where('line_bot_id', $this->bot)->count());
    }

    private function assertNoWrites(array $r): void
    {
        self::assertSame(0, DB::table('line_recovered_friends')->where('line_bot_id', $this->bot)->count());
        self::assertSame(0, DB::table('line_friend_id_staging')->where('run_id', $r['run'])->count());
        self::assertSame(0, DB::table('line_friendships')->where('line_bot_id', $this->bot)->count());
    }
}
