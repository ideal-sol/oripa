import { expect, test } from "@playwright/test";

const card = { external_id: "CARD-001", name: "Synthetic card", asset: null, allocation: 10, stored: 2, required_quantity: 12, shipped: 3, returned: 1, notion_on_hand: null, notion_available: null, notion_read_at: null };

for (const width of [1440, 390]) {
  test(`Operator inventory search, detail, warning and pagination at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    const reads: string[] = [];
    await page.route(/\/admin\/api\/v2\//u, async (route) => {
      const url = new URL(route.request().url());
      expect(route.request().method()).toBe("GET");
      const json = (body: unknown) => route.fulfill({ contentType: "application/json", body: JSON.stringify(body) });
      if (url.pathname.endsWith("/auth/session")) return json({ authenticated: true, mfa_required: false, requires_mfa_enrollment: false, admin: { id: "synthetic", role: "operator", state: "active", mfa_verified: true } });
      if (url.pathname.endsWith("/auth/permissions")) return json({ role: "operator", permissions: ["catalog.read"], request_id: "synthetic" });
      if (url.pathname.endsWith("/integrations/notion/status")) return json({ enabled: true, configured: true, latest: null, active: null, last_success_at: null, last_failure_at: null, error_code: null });
      reads.push(url.pathname + url.search);
      if (url.pathname.endsWith("/inventory/cards/CARD-001")) return json({ data: card, breakdown: [], status_counts: { stored: 2 }, next_cursor: null });
      if (url.pathname.endsWith("/inventory/unlinked-prizes")) return json({ items: [], total_count: 0, next_cursor: null });
      return json({ items: [{ ...card, external_id: url.searchParams.has("cursor") ? "CARD-002" : "CARD-001" }], next_cursor: url.searchParams.has("cursor") ? null : "next", unlinked_prize_count: 1 });
    });
    await page.goto("/inventory/cards");
    await expect(page.getByRole("button", { name: /CARD-001/ })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath(`inventory-${width}.png`), fullPage: true });
    await expect(page.getByRole("checkbox", { name: /空き在庫がマイナス/ })).toBeEnabled();
    await expect(page.getByRole("button", { name: "今すぐ同期" })).toHaveCount(0);
    await page.getByRole("button", { name: "次へ" }).click();
    await expect(page.getByRole("button", { name: /CARD-002/ })).toBeVisible();
    await page.getByLabel("管理ID", { exact: true }).fill("CARD-001");
    await page.getByRole("button", { name: "検索" }).click();
    await page.getByRole("button", { name: /CARD-001/ }).click();
    await expect(page.getByText("確保必要数：12枚")).toBeVisible();
    await page.getByRole("button", { name: "カード一覧へ戻る" }).click();
    await page.getByRole("button", { name: "管理ID未設定景品を確認" }).click();
    await expect(page.getByText("管理ID未設定の対象景品はありません。")).toBeVisible();
    expect(reads.some((path) => path.includes("cursor=next"))).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}

test("permission denial does not issue inventory requests", async ({ page }) => {
  let inventoryReads = 0;
  await page.route(/\/admin\/api\/v2\//u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.includes("/inventory/")) inventoryReads++;
    const body = path.endsWith("/auth/session")
      ? { authenticated: true, mfa_required: false, requires_mfa_enrollment: false, admin: { id: "synthetic", role: "operator", state: "active", mfa_verified: true } }
      : { role: "operator", permissions: [], request_id: "synthetic" };
    await route.fulfill({ contentType: "application/json", body: JSON.stringify(body) });
  });
  await page.goto("/inventory/cards");
  await expect(page.getByText("アクセスできません", { exact: true })).toBeVisible();
  expect(inventoryReads).toBe(0);
});

test("Admin queues an idempotent sync and sees cached negative stock", async ({ page }) => {
  await page.context().addCookies([{ name: "__Host-oripa_admin_xsrf", value: "synthetic-xsrf",
    domain: "127.0.0.1", path: "/", secure: true, sameSite: "Strict" }]);
  let queued = false;
  const run = { id: "synthetic-run", status: "queued", trigger: "manual", error_code: null, read_count: 0,
    updated_count: 0, unchanged_count: 0, duplicate_count: 0, invalid_count: 0, missing_count: 0, failed_count: 0,
    duplicate_ids: [], missing_ids: [], created_at: "2026-10-14T00:00:00Z", started_at: null, completed_at: null };
  await page.route(/\/admin\/api\/v2\//u, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const json = (body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
    if (url.pathname.endsWith("/auth/session")) return json({ authenticated: true, mfa_required: false, requires_mfa_enrollment: false, admin: { id: "synthetic", role: "admin", state: "active", mfa_verified: true } });
    if (url.pathname.endsWith("/auth/permissions")) return json({ role: "admin", permissions: ["catalog.read", "catalog.manage"], request_id: "synthetic" });
    if (url.pathname.endsWith("/integrations/notion/status")) return json({ enabled: true, configured: true, latest: queued ? run : null, active: queued ? run : null, last_success_at: null, last_failure_at: null, error_code: null });
    if (url.pathname.endsWith("/integrations/notion/sync-runs")) {
      expect(request.method()).toBe("POST");
      expect(request.headers()["x-xsrf-token"]).toBe("synthetic-xsrf");
      expect(request.headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
      queued = true;
      return json(run, 202);
    }
    return json({ items: [{ ...card, notion_on_hand: 10, notion_available: -2, notion_read_at: "2026-10-14T00:00:00Z" }], next_cursor: null, unlinked_prize_count: 0 });
  });
  await page.goto("/inventory/cards");
  await expect(page.getByRole("cell", { name: "-2", exact: true })).toBeVisible();
  await page.getByRole("button", { name: "今すぐ同期" }).click();
  await expect(page.getByText("同期待機中", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "今すぐ同期" })).toBeDisabled();
  expect(queued).toBe(true);
});
