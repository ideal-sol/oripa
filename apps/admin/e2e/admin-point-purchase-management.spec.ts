import { expect, test, type Page, type Route } from "@playwright/test";

const planId = uuid("1");

for (const width of [1440, 1366, 390]) {
  test(`Form batch ${width}px point validation and checkbox alignment`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("/purchase-plans/new");
    await expect(page.getByLabel("商品名")).toBeVisible();
    await expect(page.locator(".workspace").getByRole("alert")).toHaveCount(0);
    const checkbox = page.getByRole("checkbox", { name: "有効", exact: true });
    const control = (await checkbox.boundingBox())!;
    const label = (await page.locator(".check-row span").boundingBox())!;
    expect(control.width).toBe(18);
    expect(control.height).toBe(18);
    expect(label.x - control.x - control.width).toBeCloseTo(9, 0);
    expect(Math.abs(label.y + label.height / 2 - control.y - control.height / 2)).toBeLessThan(2);
    await page.locator(".check-row span").click();
    await expect(checkbox).not.toBeChecked();
    await checkbox.focus();
    await page.keyboard.press("Space");
    await expect(checkbox).toBeChecked();
    await page.getByLabel("商品名").focus();
    await page.getByLabel("支払金額").focus();
    await expect(page.getByText("商品名を入力してください。")).toBeVisible();
    await page.getByLabel("商品名").fill("Synthetic plan");
    await page.getByRole("button", { name: "登録", exact: true }).click();
    await expect(page.getByText("支払金額は1〜1,000,000の整数にしてください。")).toBeVisible();
    await page.getByLabel("支払金額").fill("1000");
    await page.getByLabel("付与有償ポイント").fill("1000");
    await expect(page.locator(".workspace").getByRole("alert")).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.goto(`/purchase-plans/${planId}`);
    await expect(page.getByRole("heading", { name: "設定を登録" })).toBeVisible();
    await expect(page.locator(".workspace").getByRole("alert")).toHaveCount(0);
    await page.getByRole("button", { name: "設定を登録", exact: true }).click();
    await expect(page.getByText("開始日時を入力してください。")).toBeVisible();
  });
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => { Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${token}`, set: () => undefined }); }, "a".repeat(64));
  await installApi(page);
});

test("desktop list uses canonical V1 columns and real plan data", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/purchase-plans"))?.status()).toBe(200);
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID", "商品名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "対象カテゴリ", "対象タグ", "状態", "編集",
  ]);
  await expect(page.getByText("スタンダード")).toBeVisible();
  await expect(page.getByText("初回ユーザー")).toBeVisible();
  await expect(page.getByText("VIP")).toBeVisible();
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile create form defaults to all users without horizontal overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  expect((await page.goto("/purchase-plans/new"))?.status()).toBe(200);
  await expect(page.getByLabel("対象カテゴリ")).toHaveValue("all_users");
  await expect(page.getByLabel("対象タグ")).toHaveValue("");
  await expect(page.getByRole("heading", { name: "ポイント商品登録" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect((await page.goto("/purchase-plans"))?.status()).toBe(200);
  await expect(page.getByText("スタンダード")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("creates and updates without a Fresh or Password dialog", async ({ page }) => {
  const mutations: Array<{ method: string; input: Record<string, unknown>; key: string }> = [];
  const reauthentication: string[] = [];
  page.on("request", (request) => {
    if (request.url().includes("/auth/reauthenticate")) reauthentication.push(request.url());
  });
  await page.route(/\/point-purchase-plans(?:\/[^/?]+)?$/u, async (route) => {
    const request = route.request();
    if (!["POST", "PUT"].includes(request.method())) return route.fallback();
    const input = request.postDataJSON() as Record<string, unknown>;
    mutations.push({ method: request.method(), input, key: request.headers()["idempotency-key"] ?? "" });
    return json(route, { data: plan(), idempotent_replay: false, request_id: uuid("9") });
  });
  await page.goto("/purchase-plans/new");
  await page.getByLabel("商品名").fill("Phase 2 synthetic plan");
  await page.getByLabel("支払金額").fill("1000");
  await page.getByLabel("付与有償ポイント").fill("1000");
  await page.getByRole("button", { name: "登録", exact: true }).click();
  await expect.poll(() => mutations.length).toBe(1);
  await page.goto(`/purchase-plans/${planId}`);
  await page.getByLabel("商品名").fill("Phase 2 updated plan");
  await page.getByRole("button", { name: "更新", exact: true }).click();
  await expect.poll(() => mutations.length).toBe(2);
  expect(mutations.map((mutation) => mutation.method)).toEqual(["POST", "PUT"]);
  for (const mutation of mutations) {
    expect(mutation.key).toMatch(/^[0-9a-f-]{36}$/u);
    expect(mutation.input).not.toHaveProperty("current_password");
    expect(mutation.input).not.toHaveProperty("password");
  }
  expect(mutations[1].input.expected_revision).toBe(1);
  expect(reauthentication).toEqual([]);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.getByLabel("現在のパスワード")).toHaveCount(0);
});

test("permissionless Admin cannot create or update a plan", async ({ page }) => {
  await page.route("**/auth/permissions", (route) => json(route, {
    permissions: ["payment.plan.read"], request_id: uuid("9"), role: "admin",
  }));
  await page.goto("/purchase-plans/new");
  await expect(page.getByRole("heading", { name: "アクセスできません" })).toBeVisible();
  await expect(page.getByRole("button", { name: "登録", exact: true })).toHaveCount(0);
  await page.goto(`/purchase-plans/${planId}`);
  await expect(page.getByRole("heading", { name: "アクセスできません" })).toBeVisible();
  await expect(page.getByRole("button", { name: "更新", exact: true })).toHaveCount(0);
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, { admin: { id: uuid("9"), mfa_verified: false, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (url.pathname.endsWith("/auth/permissions")) return json(route, { permissions: ["payment.plan.read", "payment.plan.manage"], request_id: uuid("9"), role: "admin" });
    if (url.pathname.includes("/user-tags")) return json(route, { items: [tag()], next_cursor: null, request_id: uuid("9") });
    if (url.pathname.endsWith("/point-purchase-plans")) return json(route, { items: [plan()], next_cursor: null, request_id: uuid("9") });
    if (url.pathname.endsWith("/limited-bonus-campaigns")) return json(route, { items: [], request_id: uuid("9") });
    if (url.pathname.includes("/point-purchase-plans/")) return json(route, { data: plan(), request_id: uuid("9") });
    return route.fulfill({ status: 404 });
  });
}

function plan() { return { amount: 1000, audience_code: "first_purchase_users", target_user_tag: { id: uuid("2"), is_active: true, name: "VIP" }, available_from: "2026-08-01T00:00:00+09:00", available_until: "2026-09-01T00:00:00+09:00", created_at: "2026-08-01T00:00:00Z", free_point_amount: 100, id: planId, is_active: true, name: "スタンダード", paid_point_amount: 1000, revision: 1, sort_order: 10, status: "published", updated_at: "2026-08-01T00:00:00Z", version: 1 }; }
function tag() { return { created_at: "2026-08-01T00:00:00Z", id: uuid("2"), is_active: true, name: "VIP", revision: 1, updated_at: "2026-08-01T00:00:00Z" }; }
function observeErrors(page: Page) { const consoleErrors: string[] = []; const pageErrors: string[] = []; const gatewayErrors: number[] = []; page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); }); page.on("pageerror", (error) => pageErrors.push(error.message)); page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); }); return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors }); }
async function json(route: Route, body: unknown): Promise<void> { await route.fulfill({ body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" }, status: 200 }); }
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
