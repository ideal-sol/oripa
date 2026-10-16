import { expect, test, type Page } from "@playwright/test";
import { memberRankFixture } from "../test/member-rank-fixture";

async function installApi(page: Page, operator = false) {
  let setting = memberRankFixture();
  const writes: { method: string; body: Record<string, unknown>; key: string | undefined }[] = [];
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => "__Host-oripa_admin_xsrf=" + "a".repeat(64), set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const json = (body: unknown) => route.fulfill({ contentType: "application/json", body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store" } });
    if (path.endsWith("/auth/session")) return json({ admin: { id: "01910191-0191-7191-8191-019101910191", mfa_verified: true, role: operator ? "operator" : "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (path.endsWith("/auth/permissions")) return json({ permissions: ["member_rank.settings.read", ...operator ? [] : ["member_rank.settings.manage"], "catalog.read"], request_id: "test", role: operator ? "operator" : "admin" });
    if (path.endsWith("/settings/member-ranks/revisions")) return json({ items: [{ before: null, after: setting.current }], next_before_revision: null, request_id: "test" });
    if (path.endsWith("/settings/member-ranks") || path.endsWith("/settings/member-ranks/scheduled")) {
      if (request.method() !== "GET") {
        const body = request.postDataJSON();
        writes.push({ method: request.method(), body, key: request.headers()["idempotency-key"] });
        if (request.method() === "PUT") {
          setting = { ...setting, revision: setting.revision + 1, scheduled: { ...setting.current, ...body, action: "schedule", revision: setting.revision + 1, effective_at: setting.next_effective_at } };
        } else setting = { ...setting, revision: setting.revision + 1, scheduled: null };
      }
      return json({ data: setting, request_id: "test", idempotent_replay: false });
    }
    return route.fulfill({ status: 404 });
  });
  return writes;
}

for (const width of [1440, 390]) {
  test(`member rank ${width}px confirms save and cancellation without enabling`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const writes = await installApi(page);
    await page.goto("/settings/member-ranks");
    await expect(page.getByText("準備中・未開始", { exact: true })).toBeVisible();
    await expect(page.locator(".workspace table").first().locator("tbody tr")).toHaveCount(7);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByLabel("ブロンズ 購入ボーナス率（無償）").fill("0.75");
    await page.getByRole("button", { name: "変更内容を確認" }).click();
    await expect(page.getByText("ブロンズ 購入ボーナス率（無償）：0.50% → 0.75%")).toBeVisible();
    expect(writes).toHaveLength(0);
    await page.getByRole("button", { name: "確認して予約を保存" }).click();
    await expect(page.getByText("翌月の設定を予約しました。")).toBeVisible();
    expect(writes[0].body.enabled).toBe(false);
    expect(writes[0].key).toMatch(/^[0-9a-f-]{36}$/u);
    await page.getByRole("button", { name: "予約を取り消す", exact: true }).click();
    await page.getByRole("button", { name: "確認して予約を取消" }).click();
    await expect(page.getByText("翌月の予約を取り消しました。")).toBeVisible();
    expect(writes[1].body.expected_revision).toBe(2);
    expect(errors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath("member-rank.png"), fullPage: true });
  });
}

test("Operator can read ranks and history but cannot save or enable", async ({ page }) => {
  const writes = await installApi(page, true);
  await page.goto("/settings/member-ranks");
  await expect(page.getByLabel("VIP 到達ライン")).toBeDisabled();
  await expect(page.getByRole("button", { name: "変更内容を確認" })).toHaveCount(0);
  await expect(page.getByRole("checkbox")).toHaveCount(0);
  await page.getByText("版 1 · 初期設定", { exact: false }).click();
  await expect(page.getByText("変更者：システム", { exact: false })).toBeVisible();
  expect(writes).toHaveLength(0);
});
