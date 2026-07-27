<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_public_list_contains_only_notice_in_publication_window(): void
    {
        $now = $this->freezeNow();
        $visible = $this->announcement([
            'title' => '公開中のお知らせ',
            'published_at' => $now->subHour(),
            'published_until' => $now->addHour(),
        ]);
        $this->announcement([
            'category' => 'lp',
            'title' => '公開中LP',
            'published_at' => $now->subHour(),
            'published_until' => $now->addHour(),
        ]);
        $this->announcement([
            'title' => '終了済み',
            'published_at' => $now->subHours(2),
            'published_until' => $now,
        ]);
        $this->announcement([
            'title' => '公開前',
            'published_at' => $now->addMinute(),
            'published_until' => $now->addHour(),
        ]);

        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.category', 'notice');
    }

    public function test_public_lp_is_available_only_by_direct_detail_url_while_active(): void
    {
        $now = $this->freezeNow();
        $lp = $this->announcement([
            'category' => 'lp',
            'published_at' => $now->subMinute(),
            'published_until' => $now->addMinute(),
        ]);

        $this->getJson("/api/announcements/{$lp->id}")
            ->assertOk()
            ->assertJsonPath('data.category', 'lp')
            ->assertJsonPath('data.robots', 'noindex, nofollow, noarchive');

        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonMissing(['id' => $lp->id]);
    }

    public function test_public_detail_rejects_future_and_expired_content(): void
    {
        $now = $this->freezeNow();
        $future = $this->announcement([
            'category' => 'lp',
            'published_at' => $now->addMinute(),
            'published_until' => $now->addHour(),
        ]);
        $expired = $this->announcement([
            'category' => 'lp',
            'published_at' => $now->subHour(),
            'published_until' => $now,
        ]);

        $this->getJson("/api/announcements/{$future->id}")->assertNotFound();
        $this->getJson("/api/announcements/{$expired->id}")->assertNotFound();
    }

    public function test_null_publication_end_is_unlimited(): void
    {
        $now = $this->freezeNow();
        $notice = $this->announcement([
            'published_at' => $now->subYears(2),
            'published_until' => null,
        ]);

        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $notice->id);
        $this->getJson("/api/announcements/{$notice->id}")->assertOk();
    }

    public function test_admin_must_submit_valid_category_and_publication_window(): void
    {
        $this->actingAdmin();

        $this->postJson('/admin/api/announcements', $this->adminPayload([
            'category' => 'unknown',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->postJson('/admin/api/announcements', $this->adminPayload([
            'published_at' => '2026-07-27T10:00:00+09:00',
            'published_until' => '2026-07-27T10:00:00+09:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('published_until');
    }

    public function test_admin_can_manage_content_outside_publication_window(): void
    {
        $this->actingAdmin();
        $announcement = $this->announcement([
            'published_at' => $this->freezeNow()->addDay(),
            'published_until' => $this->freezeNow()->addDays(2),
        ]);

        $this->getJson("/admin/api/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $announcement->id);
    }

    public function test_server_sanitizes_html_and_preview_matches_saved_rendering(): void
    {
        $this->actingAdmin();
        $body = '<h2>見出し</h2><p onclick="alert(1)">本文 <strong>強調</strong></p>'
            . '<a href="javascript:alert(1)">危険</a><script>alert(1)</script>'
            . '<iframe src="https://example.test"></iframe><form><input></form>'
            . '<table style="color:red"><tr><th>項目</th><td>値</td></tr></table>';

        $preview = $this->postJson('/admin/api/announcements/preview', ['body' => $body])
            ->assertOk()
            ->json('data.body_html');

        $response = $this->postJson('/admin/api/announcements', $this->adminPayload([
            'body' => $body,
        ]))->assertCreated();

        $rendered = $response->json('data.body_html');

        $this->assertSame($preview, $rendered);
        $this->assertStringContainsString('<h2>見出し</h2>', $rendered);
        $this->assertStringContainsString('<strong>強調</strong>', $rendered);
        $this->assertStringContainsString('<table>', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('<script', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('<form', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('style=', $rendered);
    }

    public function test_plain_text_remains_stored_verbatim_and_renders_compatibly(): void
    {
        $this->actingAdmin();
        $body = "1行目\n2行目 < 許容値";

        $response = $this->postJson('/admin/api/announcements', $this->adminPayload([
            'body' => $body,
        ]))->assertCreated();

        $this->assertSame($body, $response->json('data.body'));
        $this->assertSame('1行目<br>'."\n".'2行目 &lt; 許容値', $response->json('data.body_html'));
    }

    public function test_preexisting_unsafe_body_is_never_returned_unsanitized(): void
    {
        $announcement = $this->announcement([
            'body' => '<p onclick="alert(1)">本文</p><script>alert(1)</script>',
        ]);

        $response = $this->getJson("/api/announcements/{$announcement->id}")
            ->assertOk();

        $this->assertSame('<p>本文</p>', $response->json('data.body'));
        $this->assertSame('<p>本文</p>', $response->json('data.body_html'));
    }

    public function test_lp_cannot_be_enabled_for_top_slider(): void
    {
        $this->actingAdmin();

        $this->postJson('/admin/api/announcements', $this->adminPayload([
            'category' => 'lp',
            'show_on_top_slider' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.show_on_top_slider', false);
    }

    public function test_non_admin_cannot_create_update_or_preview(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $announcement = $this->announcement();

        $this->postJson('/admin/api/announcements', $this->adminPayload())->assertForbidden();
        $this->putJson("/admin/api/announcements/{$announcement->id}", $this->adminPayload())->assertForbidden();
        $this->postJson('/admin/api/announcements/preview', ['body' => '<p>本文</p>'])->assertForbidden();
    }

    public function test_database_constraints_reject_invalid_category_and_window(): void
    {
        $this->expectException(QueryException::class);

        DB::table('announcements')->insert([
            ...$this->adminPayload(),
            'category' => 'other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_constraint_rejects_end_at_or_before_start(): void
    {
        $this->expectException(QueryException::class);

        DB::table('announcements')->insert([
            ...$this->adminPayload(),
            'published_at' => '2026-07-27 10:00:00',
            'published_until' => '2026-07-27 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function freezeNow(): CarbonImmutable
    {
        $now = CarbonImmutable::parse('2026-07-27 12:00:00', 'Asia/Tokyo');
        CarbonImmutable::setTestNow($now);

        return $now;
    }

    private function announcement(array $overrides = []): Announcement
    {
        return Announcement::query()->create([
            ...$this->adminPayload(),
            ...$overrides,
        ]);
    }

    private function adminPayload(array $overrides = []): array
    {
        return [
            'title' => 'テスト本文',
            'body' => '既存のPlain Text',
            'category' => 'notice',
            'thumbnail_url' => null,
            'show_on_top_slider' => false,
            'status' => 'published',
            'published_at' => '2026-07-27T10:00:00+09:00',
            'published_until' => null,
            ...$overrides,
        ];
    }

    private function actingAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }
}
