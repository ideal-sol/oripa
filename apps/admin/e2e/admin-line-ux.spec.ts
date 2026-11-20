import { expect, test, type Page } from "@playwright/test";

const id = "01910191-0191-7191-8191-019101910191";
const conversation = { id, bot_id: id, member_id: null, name: "未登録の友だち", rank_level: null, points: null, status: "open", revision: 1, unread_count: 2, last_message_at: "2026-11-19T00:00:00Z", summary: "こんにちは" };
const inbound = { id: "in", sequence: 1, direction: "inbound", type: "text", body: { text: "こんにちは" }, unsent: false, media_state: "none", has_attachment: false, received_at: conversation.last_message_at, batch_status: null };
async function mockApi(page: Page) {
  await page.addInitScript(() => Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined }));
  await page.route(/\/admin\/api\/v2\//, async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body: unknown;
    if (path.endsWith("/auth/session")) body = { admin: { id, mfa_verified: true, role: "owner", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false };
    else if (path.endsWith("/auth/permissions")) body = { permissions: ["identity.line.read", "identity.line.manage", "line.bot.read", "line.bot.manage", "line.chat.read", "line.chat.reply", "line.template.read", "line.template.manage"], request_id: id, role: "owner" };
    else if (path.endsWith("/identity/line-messaging")) body = { data: { id, blocked_count: 4, friend_add_url: "https://line.me/example", friends_count: 25, linked_follow_message: "完了", login_relative_path: "/login", pending_follow_message: "ログイン", reward_enabled: false, reward_expiration_days: 180, reward_point_amount: 0, revision: 1, updated_at: conversation.last_message_at } };
    else if (path.endsWith("/settings/line-login")) body = { channel_id: "1234567", secret_configured: true, source: "database", revision: 1 };
    else if (path.endsWith("/line-bots")) body = { items: [{ id, name: "検証BOT", channel_id: "1234567", secret_configured: true, basic_id: null, display_name: null, is_active: true, legacy: false, connection_status: "succeeded", connected_at: conversation.last_message_at, quota_snapshot: null, quota_fetched_at: null, followers_snapshot: null, followers_fetched_at: null, revision: 1, webhook_path: "/webhooks/v2/line/" + id, webhook_url: "https://example.test/webhooks/v2/line/" + id }] };
    else if (path.endsWith("/line-automated-settings")) body = { data: ["shipping", "payment", "first_purchase", "rank_promotion"].map((event) => ({ event, enabled: false, revision: 0, category: "transactional", messages: [{ type: "text", text: "通知" }], effective_at: null })) };
    else if (path.endsWith("/line-chat/media")) body = { data: [], next_page: null };
    else if (path.endsWith("/line-chat")) body = { data: [conversation], next_page: null };
    else if (path.endsWith("/read") || path.endsWith("/replies")) body = { id };
    else if (path.endsWith(`/line-chat/${id}`)) body = { conversation, next_before: null, messages: [inbound, { ...inbound, id: "out", sequence: 2, direction: "outbound", body: [{ type: "text", text: "承りました" }], type: "messages", batch_status: "accepted" }] };
    else if (path.endsWith("/line-templates")) body = { bot_id: id, bots: [{ id, name: "検証BOT", is_active: true }], categories: [], data: [{ id, name: "ご案内", revision: 1, category_id: null, messages: [{ type: "text", text: "ご案内です" }] }], next_page: null };
    else if (path.endsWith("/line-deliveries/defaults")) body = { revision: 1, pacing: { push_every: 10, push_wait_seconds: 5, multicast_wait_seconds: 0 } };
    else if (path.endsWith("/line-deliveries/quota")) body = { bot_id: id, authentication_revision: 1, halted: true, halt_reason: null, periods: [] };
    else if (path.endsWith("/line-deliveries")) body = { data: [], next_cursor: null };
    else return route.fulfill({ status: 404, body: "{}" });
    return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(body) });
  });
}
for (const width of [1440, 820, 390]) test(`LINE screens share the Admin shell, aligned settings and responsive controls at ${width}px`, async ({ page }) => {
  test.setTimeout(300_000);
  await mockApi(page);
    await page.setViewportSize({ width, height: 1000 });
    for (const [path, title] of [["/line-chat", "LINEチャット"], ["/line-templates", "LINEテンプレート"], ["/line-deliveries", "LINE一斉配信"], ["/settings/line", "LINE設定"], ["/line-uploads", "LINEアップロード"]]) {
      await page.goto(path);
      await expect(page.getByRole("heading", { name: title, exact: true })).toBeVisible();
      await expect(page.getByRole("main")).toBeVisible();
      expect(await page.locator(".admin-shell").count()).toBe(1);
      if (path === "/line-deliveries") {
        await page.getByRole("button", { name: "新規配信", exact: true }).click();
        await expect(page.getByLabel("管理用名称")).toBeVisible();
        await page.getByRole("button", { name: "Quotaを取得", exact: true }).click();
        await expect(page.getByLabel("証跡UUID")).toHaveCount(0);
        await expect(page.getByRole("button", { name: "基準値を確定" })).toHaveCount(0);
        await expect(page.getByText(/自動管理状態/)).toBeVisible();
        await expect(page.getByRole("button", { name: "異常時停止" })).toHaveClass("danger-button");
      }
      if (path === "/settings/line") {
        await expect(page.getByLabel("LINE友だち追加URL")).toBeVisible();
        const boxes = await page.locator(".workspace > .settings-panel").evaluateAll((nodes) => nodes.map((node) => ({ x: node.getBoundingClientRect().x, width: node.getBoundingClientRect().width })));
        expect(boxes).toHaveLength(3);
        for (const box of boxes) { expect(box.width).toBeLessThanOrEqual(860); expect(Math.abs(box.x - boxes[0].x)).toBeLessThan(1); expect(Math.abs(box.width - boxes[0].width)).toBeLessThan(1); }
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      expect(await page.locator(".workspace button").evaluateAll((nodes) => nodes.every((node) => /(?:primary|secondary|danger)-button/.test(node.className)))).toBe(true);
      await page.screenshot({ path: `/tmp/lineux-${path.replaceAll("/", "-")}-${width}.png`, fullPage: true });
    }
});
test("chat navigates to individual history with separate bubbles and back", async ({ page }) => {
  test.setTimeout(90_000); await mockApi(page);
  await page.goto("/line-chat");
  await page.getByRole("link", { name: /未登録の友だち.*未読 2/ }).click();
  await expect(page).toHaveURL(new RegExp(`/line-chat/${id}$`));
  await expect(page.getByLabel("受信メッセージ")).toContainText("こんにちは");
  await expect(page.getByLabel("送信メッセージ")).toContainText("LINE API受付済み（配達・既読未確認）");
  const left = await page.getByLabel("受信メッセージ").boundingBox(); const right = await page.getByLabel("送信メッセージ").boundingBox();
  expect(right!.x).toBeGreaterThan(left!.x);
  await page.getByLabel("返信本文").fill("返信テスト");
  const request = page.waitForRequest((r) => r.url().endsWith("/replies") && r.method() === "POST");
  await page.getByRole("button", { name: "返信を送信キューに登録" }).click();
  expect((await request).postDataJSON().messages).toEqual([{ type: "text", text: "返信テスト" }]);
  await page.getByRole("link", { name: "一覧へ戻る" }).click(); await expect(page).toHaveURL(/\/line-chat$/);
});
test("uncertain reply survives sidebar navigation and confirms the identical request", async ({ page }) => {
  test.setTimeout(300_000); await mockApi(page);
  const requests: unknown[] = [];
  await page.route("**/replies", async (route) => {
    requests.push(route.request().postDataJSON());
    if (requests.length === 1) return route.abort("timedout");
    return route.fulfill({ contentType: "application/json", body: JSON.stringify({ id }) });
  });
  await page.goto(`/line-chat/${id}`); await expect(page.getByLabel("返信本文")).toBeEnabled();
  const origin = await page.evaluate(() => performance.timeOrigin);
  await page.getByLabel("返信本文").fill("navigation retained reply");
  await page.getByRole("button", { name: "返信を送信キューに登録" }).click();
  await expect(page.getByRole("button", { name: "同じ送信要求の結果を確認" })).toBeEnabled();
  await page.locator('.admin-sidebar a[href="/line-templates"]').click();
  await expect(page.getByRole("heading", { name: "LINEテンプレート", exact: true })).toBeVisible();
  await page.locator('.admin-sidebar a[href="/line-chat"]').click();
  await page.getByRole("link", { name: /未登録の友だち.*未読 2/ }).click();
  await expect(page.getByLabel("返信本文")).toHaveValue("navigation retained reply");
  await expect(page.getByLabel("返信本文")).toBeDisabled();
  expect(await page.evaluate(() => performance.timeOrigin)).toBe(origin);
  await page.getByRole("button", { name: "同じ送信要求の結果を確認" }).click();
  await expect(page.getByText("送信キューに登録しました。", { exact: true })).toBeVisible();
  expect(requests).toHaveLength(2); expect(requests[1]).toEqual(requests[0]);
});
test("registered reply with failed history recovers through GET alone and reload requires history review", async ({ page }) => {
  test.setTimeout(300_000); await mockApi(page);
  let registrations = 0; let failHistory = false;
  await page.route("**/replies", async (route) => {
    registrations++; failHistory = true;
    return route.fulfill({ contentType: "application/json", body: JSON.stringify({ id }) });
  });
  await page.route(`**/line-chat/${id}*`, async (route) => {
    if (new URL(route.request().url()).pathname.endsWith(`/line-chat/${id}`) && failHistory) return route.abort("failed");
    return route.fallback();
  });
  await page.goto(`/line-chat/${id}`); await expect(page.getByLabel("返信本文")).toBeEnabled();
  await page.getByLabel("返信本文").fill("registered once");
  await page.getByRole("button", { name: "返信を送信キューに登録" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("返信は送信キューに登録されましたが、履歴の更新に失敗しました");
  await expect(page.getByRole("button", { name: "返信を送信キューに登録" })).toHaveCount(0);
  failHistory = false; await page.getByRole("button", { name: "最新履歴を取得" }).click();
  await expect(page.getByText("送信キューに登録しました。", { exact: true })).toBeVisible(); expect(registrations).toBe(1);
  await page.reload(); await expect(page.getByRole("button", { name: "履歴を確認しました（新しい返信を作成）" })).toBeEnabled();
  await expect(page.getByLabel("返信本文")).toBeDisabled();
  await page.getByRole("button", { name: "履歴を確認しました（新しい返信を作成）" }).click();
  await expect(page.getByLabel("返信本文")).toBeEnabled(); expect(registrations).toBe(1);
});
