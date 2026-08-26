import { expect, test, type Page, type Route } from "@playwright/test";

const rankId = uuid("1");
const effectId = uuid("2");

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
    Object.defineProperty(URL, "createObjectURL", {
      configurable: true,
      value: () => "data:image/png;base64,iVBORw0KGgo=",
    });
    Object.defineProperty(URL, "revokeObjectURL", {
      configurable: true,
      value: () => undefined,
    });
  }, "a".repeat(64));
  await installApi(page);
});

test("desktop list and edit preserve the existing asset without relation input", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/catalog/presentation-assets"))?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "ランク演出" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "種別", "タイトル", "ランク", "プレビュー", "表示順", "状態", "更新日時", "操作",
  ]);
  await page.getByRole("link", { name: "当選演出を編集" }).click();
  await expect(page.getByRole("heading", { name: "ランク演出編集" })).toBeVisible();
  await expect(page.getByLabel("ファイル差し替え（任意）")).not.toHaveAttribute("required");
  await expect(page.getByRole("img", { name: "ランク演出プレビュー" })).toBeVisible();
  await expect(page.getByText("Rank relation")).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "対象ランクと表示順" })).toHaveCount(0);
  await page.getByLabel("タイトル").fill("更新演出");
  await page.getByRole("button", { name: "保存" }).click();
  await expect(page.getByText("ランク演出を保存しました。")).toBeVisible();
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile new form supports direct image or video upload without overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  await page.goto("/catalog/presentation-assets/new");
  await expect(page.getByRole("heading", { name: "ランク演出登録" })).toBeVisible();
  await expect(page.getByLabel("ファイル")).toHaveAttribute("required");
  await expect(page.getByLabel("画像")).toBeChecked();
  await expect(page.getByLabel("動画")).not.toBeChecked();
  await expect(page.getByText("Rank relation")).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "対象ランクと表示順" })).toHaveCount(0);
  await page.getByLabel("タイトル").fill("新規演出");
  await page.getByLabel("ファイル").setInputFiles({
    buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64"),
    mimeType: "image/png",
    name: "effect.png",
  });
  await page.getByRole("button", { name: "保存" }).click();
  await expect(page).toHaveURL(`/catalog/presentation-assets/${effectId}/edit`);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, { admin: { id: uuid("9"), mfa_verified: false, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (url.pathname.endsWith("/auth/permissions")) return json(route, { permissions: ["catalog.read", "catalog.manage"], request_id: uuid("9"), role: "admin" });
    if (url.pathname.endsWith("/catalog/ranks")) return json(route, { items: [{ archived_at: null, code: "S", created_at: "2026-08-05T00:00:00Z", description: null, id: rankId, image_asset: null, is_archived: false, is_visible: true, name: "Sランク", revision: 1, sort_order: 4, updated_at: "2026-08-05T00:00:00Z", video_asset: null }], next_cursor: null });
    if (url.pathname.endsWith("/catalog/rank-effects") && route.request().method() === "POST") return rankEffectMutation(route, true);
    if (url.pathname === `/admin/api/v2/catalog/rank-effects/${effectId}` && route.request().method() === "PUT") return rankEffectMutation(route, false);
    if (url.pathname === `/admin/api/v2/catalog/rank-effects/${effectId}`) return json(route, { data: effect() });
    if (url.pathname.endsWith("/catalog/rank-effects")) return json(route, { items: [effect()], next_cursor: null });
    if (url.pathname.endsWith(`/catalog/presentation-assets/${effectId}/content`)) return route.fulfill({ body: Buffer.from("iVBORw0KGgo=", "base64"), contentType: "image/png", status: 200 });
    return route.fulfill({ status: 404 });
  });
}

async function rankEffectMutation(route: Route, creating: boolean): Promise<void> {
  const payload = route.request().postDataJSON() as Record<string, unknown>;
  if (Object.hasOwn(payload, "rank_assignments")) {
    await route.fulfill({ status: 422 });
    return;
  }
  await json(route, {
    data: {
      ...effect(),
      alt_text: payload.title,
      rank_assignments: creating ? [] : effect().rank_assignments,
      revision: creating ? 1 : 2,
    },
    idempotent_replay: false,
  }, creating ? 201 : 200);
}

function effect() { return { alt_text: "当選演出", archived_at: null, byte_size: 68, checksum_sha256: "a".repeat(64), content_path: `/admin/api/v2/catalog/presentation-assets/${effectId}/content`, created_at: "2026-08-05T00:00:00Z", id: effectId, is_archived: false, is_public: true, media_type: "image", mime_type: "image/png", public_path: `/admin/api/v2/catalog/presentation-assets/${effectId}/content`, rank_assignments: [{ rank: { code: "S", id: rankId, name: "Sランク" }, sort_order: 4 }], revision: 1, updated_at: "2026-08-05T00:00:00Z" }; }
function observeErrors(page: Page) { const consoleErrors: string[] = []; const pageErrors: string[] = []; const gatewayErrors: number[] = []; page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); }); page.on("pageerror", (error) => pageErrors.push(error.message)); page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); }); return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors }); }
async function json(route: Route, body: unknown, status = 200): Promise<void> { await route.fulfill({ body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" }, status }); }
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
