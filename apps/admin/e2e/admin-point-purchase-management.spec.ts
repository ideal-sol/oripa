import { expect, test, type Page, type Route } from "@playwright/test";

const planId = uuid("1");

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => { Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${token}`, set: () => undefined }); }, "a".repeat(64));
  await installApi(page);
});

test("desktop list uses canonical V1 columns and real plan data", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/purchase-plans"))?.status()).toBe(200);
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID", "商品名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "対象カテゴリ", "状態", "編集",
  ]);
  await expect(page.getByText("スタンダード")).toBeVisible();
  await expect(page.getByText("初回ユーザー")).toBeVisible();
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile create form defaults to all users without horizontal overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  expect((await page.goto("/purchase-plans/new"))?.status()).toBe(200);
  await expect(page.getByLabel("対象カテゴリ")).toHaveValue("all_users");
  await expect(page.getByRole("heading", { name: "ポイント商品登録" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect((await page.goto("/purchase-plans"))?.status()).toBe(200);
  await expect(page.getByText("スタンダード")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, { admin: { id: uuid("9"), mfa_verified: true, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (url.pathname.endsWith("/auth/permissions")) return json(route, { permissions: ["payment.plan.read", "payment.plan.manage"], request_id: uuid("9"), role: "admin" });
    if (url.pathname.endsWith("/point-purchase-plans")) return json(route, { items: [plan()], next_cursor: null, request_id: uuid("9") });
    if (url.pathname.includes("/point-purchase-plans/")) return json(route, { data: plan(), request_id: uuid("9") });
    return route.fulfill({ status: 404 });
  });
}

function plan() { return { amount: 1000, audience_code: "first_purchase_users", available_from: "2026-08-01T00:00:00+09:00", available_until: "2026-09-01T00:00:00+09:00", created_at: "2026-08-01T00:00:00Z", free_point_amount: 100, id: planId, is_active: true, name: "スタンダード", paid_point_amount: 1000, revision: 1, sort_order: 10, status: "published", updated_at: "2026-08-01T00:00:00Z", version: 1 }; }
function observeErrors(page: Page) { const consoleErrors: string[] = []; const pageErrors: string[] = []; const gatewayErrors: number[] = []; page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); }); page.on("pageerror", (error) => pageErrors.push(error.message)); page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); }); return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors }); }
async function json(route: Route, body: unknown): Promise<void> { await route.fulfill({ body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" }, status: 200 }); }
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
