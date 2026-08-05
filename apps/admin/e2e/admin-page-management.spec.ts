import { expect, test, type Page, type Route } from "@playwright/test";

const categoryId = uuid("1");
const pageId = uuid("2");

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => { Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${token}`, set: () => undefined }); }, "a".repeat(64));
  await installApi(page);
});

test("desktop page list and edit routes use canonical data", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/settings/pages"))?.status()).toBe(200);
  await expect(page.getByRole("columnheader")).toHaveText(["ページ", "URL", "カテゴリ", "表示状態", "更新日時", "編集"]);
  await page.getByRole("link", { name: "ご利用ガイドを編集" }).click();
  await expect(page.getByRole("heading", { name: "ページ編集" })).toBeVisible();
  await expect(page.getByLabel("タイトル")).toHaveValue("ご利用ガイド");
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile create route opens an accessible category dialog without overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  expect((await page.goto("/settings/pages/new"))?.status()).toBe(200);
  await page.getByRole("button", { name: "カテゴリ追加" }).click();
  await expect(page.getByRole("dialog", { name: "カテゴリ追加" })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog", { name: "カテゴリ追加" })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, { admin: { id: uuid("9"), mfa_verified: false, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (url.pathname.endsWith("/auth/permissions")) return json(route, { permissions: ["content.read", "content.manage"], request_id: uuid("9"), role: "admin" });
    if (url.pathname.endsWith("/page-management/categories")) return json(route, { items: [{ created_at: "2026-08-05T00:00:00Z", id: categoryId, name: "ご利用案内", visibility: "visible" }] });
    if (url.pathname.includes("/page-management/pages/")) return json(route, managedPage());
    if (url.pathname.endsWith("/page-management/pages")) return json(route, { items: [managedPage()], next_cursor: null });
    return route.fulfill({ status: 404 });
  });
}
function managedPage() { return { body_html: "<p>本文</p>", category: { created_at: "2026-08-05T00:00:00Z", id: categoryId, name: "ご利用案内", visibility: "visible" }, created_at: "2026-08-05T00:00:00Z", id: pageId, slug: "guide", title: "ご利用ガイド", updated_at: "2026-08-05T01:00:00Z", version_id: uuid("4"), version_number: 1, visibility: "visible" }; }
function observeErrors(page: Page) { const consoleErrors: string[] = []; const pageErrors: string[] = []; const gatewayErrors: number[] = []; page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); }); page.on("pageerror", (error) => pageErrors.push(error.message)); page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); }); return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors }); }
async function json(route: Route, body: unknown): Promise<void> { await route.fulfill({ body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" }, status: 200 }); }
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
