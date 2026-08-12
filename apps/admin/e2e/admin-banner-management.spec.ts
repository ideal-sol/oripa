import { expect, test, type Page, type Route } from "@playwright/test";

const categoryId = uuid("1");
const bannerId = uuid("2");
const publicAssetUrl = `https://test.luxe-pack.biz/api/v2/content/assets/${uuid("3")}`;

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, "a".repeat(64));
  await installApi(page);
});

test("desktop banner management renders exact columns, filter, and dialogs", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/banners"))?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "バナー管理" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "アップロード画像", "タイトル", "カテゴリ", "画像URL", "登録日", "編集", "削除",
  ]);
  await expect(page.getByText(publicAssetUrl)).toBeVisible();
  await page.getByLabel("カテゴリ絞り込み").selectOption(categoryId);
  await page.getByRole("button", { name: "メインバナーを編集" }).click();
  await expect(page.getByRole("dialog", { name: "バナー編集" })).toBeVisible();
  await page.getByRole("button", { name: "バナー編集を閉じる" }).click();
  await page.getByRole("button", { name: "メインバナーを削除" }).click();
  await expect(page.getByRole("dialog", { name: "バナー削除" })).toContainText("共有画像Assetは保持");
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile banner form and table stay inside the viewport", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  await page.goto("/banners#banner-create");
  await expect(page.getByRole("heading", { name: "バナー登録" })).toBeVisible();
  await page.getByRole("button", { name: "カテゴリ追加" }).click();
  await expect(page.getByRole("dialog", { name: "カテゴリ追加" })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog", { name: "カテゴリ追加" })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  await page.route(publicAssetUrl, async (route) => route.fulfill({
    body: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64"),
    headers: {
      "Cache-Control": "public, max-age=31536000, immutable",
      "Content-Type": "image/png",
    },
    status: 200,
  }));
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, { admin: { id: uuid("9"), mfa_verified: false, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (url.pathname.endsWith("/auth/permissions")) return json(route, { permissions: ["content.read", "content.manage"], request_id: uuid("9"), role: "admin" });
    if (url.pathname.endsWith("/banner-management/categories")) return json(route, { items: [{ created_at: "2026-08-05T00:00:00Z", id: categoryId, name: "トップ" }] });
    if (url.pathname.endsWith("/banner-management/banners")) {
      if (url.searchParams.get("category_id")) expect(url.searchParams.get("category_id")).toBe(categoryId);
      return json(route, { items: [{ asset: { id: uuid("3"), public_url: publicAssetUrl }, category: { id: categoryId, name: "トップ" }, created_at: "2026-08-05T00:00:00Z", id: bannerId, status: "draft", title: "メインバナー", updated_at: "2026-08-05T00:00:00Z", version_id: uuid("4"), version_number: 1 }], next_cursor: null });
    }
    return route.fulfill({ status: 404 });
  });
}

function observeErrors(page: Page) {
  const consoleErrors: string[] = []; const pageErrors: string[] = []; const gatewayErrors: number[] = [];
  page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); });
  return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors });
}

async function json(route: Route, body: unknown): Promise<void> { await route.fulfill({ body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" }, status: 200 }); }
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
