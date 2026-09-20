import { expect, test, type Page, type Route } from "@playwright/test";

for (const width of [1440, 1366, 390]) {
  test(`UI display ${width}px /banners table geometry`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });

    await page.goto("/banners");
    const filter = page.locator(".admin-payment-filters");
    await expect(filter).toHaveCSS("padding", "16px");
    await expect(filter.locator("select")).toHaveCount(2);
    for (const select of await filter.locator("select").all()) {
      expect((await select.boundingBox())!.height).toBeGreaterThanOrEqual(42);
    }
    const table = page.locator(".banner-list-table");
    await expect(table.locator("tbody tr").first()).toBeVisible();
    await expect(table.locator("..")).toHaveCSS("overflow-x", "auto");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const cells = await table.evaluate((element) => {
      const headers = Array.from(element.querySelectorAll("thead th"));
      return Array.from(element.querySelectorAll("tbody tr:first-child td")).map((cell, index) => {
        const header = getComputedStyle(headers[index]);
        const body = getComputedStyle(cell);
        return { headerAlignment: header.textAlign, bodyAlignment: body.textAlign, headerPadding: header.padding, bodyPadding: body.padding, vertical: body.verticalAlign, border: body.borderBottomStyle, background: header.backgroundColor };
      });
    });
    for (const cell of cells) {
      expect(cell.headerAlignment).toBe(cell.bodyAlignment);
      expect(cell.headerPadding).toBe(cell.bodyPadding);
      expect(cell.vertical).toBe("middle");
      expect(cell.border).toBe("solid");
      expect(cell.background).not.toBe("rgba(0, 0, 0, 0)");
    }
    await expect(page.locator(".announcement-pagination")).toHaveCSS("justify-content", "flex-end");


    if (width > 1000) {
      const region = table.locator("..");
      const actions = table.locator("tbody tr:first-child td:nth-last-child(-n + 3)");
      for (const actionCell of await actions.all()) {
        await expect(actionCell).toHaveCSS("position", "sticky");
        await expect(actionCell.locator("button")).toBeInViewport();
        const button = actionCell.locator("button");
        const buttonBox = (await button.boundingBox())!;
        expect(await button.evaluate((element, point) => element.contains(document.elementFromPoint(point.x, point.y)), { x: buttonBox.x + buttonBox.width / 2, y: buttonBox.y + buttonBox.height / 2 })).toBe(true);
      }
      await region.evaluate((element) => { element.scrollLeft = element.scrollWidth; });
      const action = table.locator("tbody tr:first-child td:last-child").locator("button, a").last();
      await expect(action).toBeInViewport();
      const box = (await action.boundingBox())!;
      expect(await action.evaluate((element, point) => element.contains(document.elementFromPoint(point.x, point.y)), { x: box.x + box.width / 2, y: box.y + box.height / 2 })).toBe(true);
    }
    await page.screenshot({ path: testInfo.outputPath("table.png"), fullPage: true });
  });
}

const categoryId = uuid("1");
const bannerId = uuid("2");
const publicAssetUrl = `/api/v2/content/assets/${uuid("3")}`;

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
    "アップロード画像", "タイトル", "カテゴリ", "状態", "Version", "トップ表示", "画像URL", "登録日", "公開", "編集", "削除",
  ]);
  await expect(page.getByText("Draft")).toBeVisible();
  await expect(page.getByText("v1")).toBeVisible();
  await expect(page.getByText("/gachas")).toBeVisible();
  await expect(page.getByText(publicAssetUrl)).toBeVisible();
  await page.getByLabel("カテゴリ絞り込み").selectOption(categoryId);
  await page.getByRole("button", { name: "メインバナーを編集" }).click();
  await expect(page.getByRole("dialog", { name: "バナー編集" })).toBeVisible();
  await expect(page.getByRole("dialog", { name: "バナー編集" }).getByLabel("トップに表示")).toBeChecked();
  await expect(page.getByRole("dialog", { name: "バナー編集" }).getByLabel("クリック先URL")).toHaveValue("/gachas");
  await page.getByRole("button", { name: "バナー編集を閉じる" }).click();
  await page.getByRole("button", { name: "公開する" }).click();
  await expect(page.getByText("Published")).toBeVisible();
  await expect(page.getByRole("button", { name: "公開する" })).toHaveCount(0);
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
  let published = false;
  await page.route(`**${publicAssetUrl}`, async (route) => route.fulfill({
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
    if (url.pathname.endsWith("/auth/permissions")) return json(route, { permissions: ["content.read", "content.manage", "content.publish"], request_id: uuid("9"), role: "admin" });
    if (url.pathname.endsWith("/banner-management/categories")) return json(route, { items: [{ created_at: "2026-08-05T00:00:00Z", id: categoryId, name: "トップ" }] });
    if (url.pathname.endsWith("/banner-management/banners")) {
      if (url.searchParams.get("category_id")) expect(url.searchParams.get("category_id")).toBe(categoryId);
      return json(route, { items: [{ asset: { id: uuid("3"), public_url: publicAssetUrl }, category: { id: categoryId, name: "トップ" }, created_at: "2026-08-05T00:00:00Z", id: bannerId, link_url: "/gachas", show_on_top: true, status: published ? "published" : "draft", title: "メインバナー", updated_at: "2026-08-05T00:00:00Z", version_id: uuid("4"), version_number: 1 }], next_cursor: null });
    }
    if (url.pathname.endsWith(`/content/banners/${bannerId}/versions/${uuid("4")}/publish`)) {
      published = true;
      return json(route, { id: bannerId, identifier: "main-banner", is_legal: false, status: "published", versions: [] });
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
