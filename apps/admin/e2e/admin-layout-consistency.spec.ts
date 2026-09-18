import { expect, test, type Page } from "@playwright/test";

const fixtureId = "01910191-0191-7191-8191-019101910191";
const timestamp = "2026-09-18T00:00:00Z";
const agency = {
  id: fixtureId, company_name: "レイアウト確認代理店", contact_name: "サンプル担当者",
  email: "layout@example.test", phone: "03-0000-0000", address: "表示確認用住所",
  memo: "長いメモ".repeat(40), login_id: "000012", advertising_code: "ABC123xy",
  status: "active", revision: 1, created_at: timestamp, updated_at: timestamp,
};

const screens = [
  { path: "/purchase-plans", title: "ポイント購入商品", ready: "スタンダード", table: true },
  { path: "/catalog/ranks", title: "ランク", ready: "SSランク", table: true },
  { path: "/settings/referral", title: "紹介ポイント設定", ready: "紹介者へ付与するポイント", table: false },
  { path: "/settings/line", title: "LINE設定", ready: "LINE友だち追加URL", table: false },
  { path: "/agencies", title: "代理店一覧", ready: agency.company_name, table: true },
  { path: `/agencies/${fixtureId}`, title: "代理店詳細", ready: agency.company_name, table: false },
  { path: `/agencies/${fixtureId}/edit`, title: "代理店編集", ready: "会社名", table: false },
];

for (const width of [1440, 390]) {
  for (const screen of screens) {
    test(`${width}px ${screen.title} follows the Admin content boundaries`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 900 });
      const errors: string[] = [];
      page.on("pageerror", (error) => errors.push(error.message));
      await installLayoutApi(page);
      await page.goto(screen.path);
      await expect(page.getByRole("heading", { name: screen.title, exact: true })).toBeVisible();
      await expect(page.getByText(screen.ready, { exact: true }).first()).toBeVisible();
      const geometry = await page.locator(".workspace").evaluate((workspace) => {
        const main = document.querySelector(".admin-main")!;
        const style = getComputedStyle(main);
        const mainBox = main.getBoundingClientRect();
        const box = workspace.getBoundingClientRect();
        return {
          left: box.left, right: box.right, width: box.width,
          expectedLeft: mainBox.left + parseFloat(style.paddingLeft),
          expectedRight: mainBox.right - parseFloat(style.paddingRight),
          overflow: document.documentElement.scrollWidth > window.innerWidth,
        };
      });
      expect(geometry.overflow).toBe(false);
      expect(geometry.left).toBe(geometry.expectedLeft);
      expect(geometry.right).toBe(geometry.expectedRight);
      if (screen.table) {
        const table = page.locator(".workspace table").first();
        await expect(table).toBeVisible();
        const region = table.locator("..");
        await expect(region).toHaveCSS("overflow-x", "auto");
        expect((await region.boundingBox())!.width).toBeLessThanOrEqual(geometry.width);
        await expect(table.locator("th").first()).toHaveCSS("padding-left", "14px");
        if (width === 390) {
          expect(await region.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
          await region.evaluate((element) => { element.scrollLeft = element.scrollWidth; });
          expect(await region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
        }
      }
      if (screen.path.startsWith("/settings/")) {
        const form = page.locator(".workspace form");
        expect((await form.boundingBox())!.width).toBeLessThanOrEqual(860);
        await expect(page.getByRole("button", { name: "保存", exact: true })).toBeVisible();
      }
      if (screen.title === "代理店詳細") {
        expect((await page.locator(".contact-detail-card").boundingBox())!.width).toBe(geometry.width);
        await expect(page.getByRole("button", { name: "ログイン情報を再発行" })).toBeVisible();
      }
      expect(errors).toEqual([]);
      await page.screenshot({ path: testInfo.outputPath("layout.png"), fullPage: true });
    });
  }
}

async function installLayoutApi(page: Page) {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    const json = (body: unknown) => route.fulfill({ contentType: "application/json", body: JSON.stringify(body) });
    const data = (value: unknown) => json({ data: value, request_id: fixtureId });
    const collection = (items: unknown[]) => json({ items, next_cursor: null, request_id: fixtureId });
    if (path.endsWith("/auth/session")) return json({ admin: { id: fixtureId, mfa_verified: true, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (path.endsWith("/auth/permissions")) return json({ role: "admin", request_id: fixtureId, permissions: ["payment.plan.read", "payment.plan.manage", "catalog.read", "catalog.manage", "referral.settings.read", "referral.settings.manage", "identity.line.read", "identity.line.manage", "agency.read", "agency.manage"] });
    if (path.endsWith("/agencies")) return collection([agency]);
    if (path.endsWith(`/agencies/${fixtureId}`)) return data(agency);
    if (path.endsWith(`/catalog/presentation-assets/${fixtureId}/content`)) return route.fulfill({ contentType: "image/png", body: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64") });
    if (path.endsWith("/catalog/ranks")) return collection([{ id: fixtureId, rank_name: "SSランク", lineup_image: { id: fixtureId, alt_text: "ラインナップ画像" }, result_image: { id: fixtureId, alt_text: "抽選結果画像" }, show_total_stock: true, status: "active", display_order: 0, revision: 1, revision_number: 1, created_at: timestamp, updated_at: timestamp }]);
    if (path.endsWith("/user-tags")) return collection([]);
    if (path.endsWith("/point-purchase-plans")) return collection([{ id: fixtureId, amount: 1000, paid_point_amount: 1000, free_point_amount: 100, name: "スタンダード", audience_code: "all_users", target_user_tag: null, available_from: null, available_until: null, is_active: true, status: "published", sort_order: 1, version: 1, revision: 1, created_at: timestamp, updated_at: timestamp }]);
    if (path.endsWith("/settings/referral-points")) return data({ id: fixtureId, applies_to: "future_referrals_only", grant_condition: "referred_user_sms_verified", grant_timing: "on_sms_verification_completion", is_enabled: true, referred_user_point_amount: 50, referrer_point_amount: 100, reward_expiration_days: 180, revision: 1, updated_at: timestamp });
    if (path.endsWith("/identity/line-messaging")) return data({ id: fixtureId, friend_add_url: "https://line.me/R/ti/p/example", friends_count: 25, blocked_count: 4, linked_follow_message: "完了しました", pending_follow_message: "{login_url} からログイン", login_relative_path: "/login", reward_enabled: false, reward_expiration_days: 180, reward_point_amount: 0, revision: 1, updated_at: timestamp });
    return route.fulfill({ status: 404 });
  });
}
