import { expect, test, type Page, type Route } from "@playwright/test";

const gachaId = "A7k9P2x4Qm8";
const rankId = "0198a001-0000-7000-8000-000000000001";
const imageId = "0198a001-0000-7000-8000-000000000005";
const videoId = "0198a001-0000-7000-8000-000000000006";

test("registration offers three separate routes and daily has no standard-only fields", async ({ page }) => {
  await installApi(page);
  await page.goto("/catalog/gachas/new");
  await expect(page.getByRole("link", { name: "通常ガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/standard");
  await expect(page.getByRole("link", { name: "新規登録限定ガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/signup");
  await page.getByRole("link", { name: "ログインガチャを登録" }).click();
  await expect(page.getByLabel("消費ポイント", { exact: true })).toHaveValue("0");
  await expect(page.getByLabel("最低保証（交換ポイント）")).toHaveValue("0");
  for (const label of ["総口数", "カテゴリ", "タグ", "発送専用", "対象ユーザー", "ガチャ種別"]) {
    await expect(page.getByLabel(label, { exact: true })).toHaveCount(0);
  }
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test("daily sends a complete exact composition once with JST and an idempotency key", async ({ page }) => {
  const mutations = await installApi(page);
  await page.goto("/catalog/gachas/new/login");
  await page.getByLabel("ガチャ名", { exact: true }).fill("Daily browser fixture");
  await page.getByLabel("サムネイル", { exact: true }).selectOption(imageId);
  await page.getByLabel("公開開始日時（JST）").fill("2026-10-01T00:00");
  await page.getByRole("button", { name: "景品を追加" }).click();
  await page.getByLabel("景品名", { exact: true }).fill("Prize fixture");
  await page.getByLabel("景品画像", { exact: true }).selectOption(imageId);
  await page.getByLabel("Aの演出動画", { exact: true }).selectOption(videoId);
  await page.getByLabel("固定当選確率（%・小数10桁まで）").fill("99.9999999999");
  await expect(page.getByRole("button", { name: "構成を一括保存" })).toBeDisabled();
  await page.getByLabel("固定当選確率（%・小数10桁まで）").fill("100.0000000000");
  await expect(page.getByText("確率合計: 100%", { exact: false })).toBeVisible();
  await page.getByRole("button", { name: "構成を一括保存" }).click();
  await expect.poll(() => mutations.length).toBe(1);
  expect(mutations[0].body).toMatchObject({ gacha_type: "login_daily", price_points: 0, total_count: null, category_id: null,
    publish_start_at: "2026-10-01T00:00:00+09:00", prizes: [{ percentage: "100.0000000000", shipping_only: false }] });
  expect(mutations[0].key).toMatch(/^[0-9a-f-]{36}$/u);
});

test("copy is read-only until save and preserves the smallest rate and frozen presentation", async ({ page }) => {
  const mutations = await installApi(page);
  await page.goto(`/catalog/gachas/${gachaId}/copy`);
  await expect(page.getByLabel("ガチャ名", { exact: true })).toHaveValue("Published login fixture");
  await expect(page.getByLabel("公開開始日時（JST）")).toHaveValue("");
  await expect(page.getByLabel("固定当選確率（%・小数10桁まで）").first()).toHaveValue("0.0000000001");
  await expect(page.getByLabel("ランク", { exact: true }).first().locator("option:checked")).toHaveText("Frozen A");
  expect(mutations).toHaveLength(0);
  await page.getByLabel("公開開始日時（JST）").fill("2026-10-02T00:00");
  await page.getByRole("button", { name: "構成を一括保存" }).click();
  await expect.poll(() => mutations.length).toBe(1);
  expect(mutations[0].body).toMatchObject({ prizes: [{ percentage: "0.0000000001" }, { percentage: "99.9999999999" }],
    ranks: [{ rank_id: rankId, rank_revision_number: 1, video_asset_id: videoId }] });
  expect(mutations[0].body).not.toHaveProperty("id");
});

test("signup is explicitly free and omits price and post-registration day inputs", async ({ page }) => {
  await installApi(page);
  await page.goto("/catalog/gachas/new/signup");
  await expect(page.getByText("消費ポイント: 無料（0）")).toBeVisible();
  await expect(page.getByLabel("消費ポイント", { exact: true })).toHaveCount(0);
  await expect(page.getByLabel("最低保証（交換ポイント）")).toHaveCount(0);
  await expect(page.getByLabel("初回ユーザー期間（日）")).toHaveCount(0);
});

async function installApi(page: Page) {
  const mutations: { body: Record<string, unknown>; key: string | undefined }[] = [];
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, { authenticated: true, mfa_required: false, requires_mfa_enrollment: false,
      admin: { id: rankId, role: "owner", state: "active", mfa_verified: true } });
    if (path.endsWith("/auth/permissions")) return json(route, { role: "owner", request_id: rankId, permissions: ["catalog.read", "catalog.manage", "catalog.publish"] });
    if (path.endsWith("/catalog/categories") || path.endsWith("/catalog/tags")) return json(route, { items: [], next_cursor: null });
    if (path.endsWith("/catalog/ranks")) return json(route, { items: [{ id: rankId, rank_name: "A", status: "active" }], next_cursor: null });
    if (path.endsWith("/catalog/presentation-assets")) return json(route, { items: [
      { id: imageId, media_type: "image", mime_type: "image/png", is_public: true, public_path: null, alt_text: "Image fixture" },
      { id: videoId, media_type: "video", mime_type: "video/mp4", is_public: true, public_path: null, alt_text: "Video fixture" },
    ], next_cursor: null });
    if (path.endsWith(`/catalog/gachas/${gachaId}/copy`)) return json(route, { data: projection() });
    if (path.endsWith(`/catalog/gachas/${gachaId}`)) return json(route, { data: { id: rankId, public_code: gachaId, gacha_type: "login_daily",
      publication_status: "published", first_published_at: "2026-07-01T00:00:00Z", revision: 1 } });
    if (path.endsWith("/catalog/gacha-compositions") && route.request().method() === "POST") {
      mutations.push({ body: route.request().postDataJSON() as Record<string, unknown>, key: route.request().headers()["idempotency-key"] });
      return route.fulfill({ status: 409, contentType: "application/problem+json", body: JSON.stringify({ code: "CATALOG_REVISION_CONFLICT", status: 409, request_id: rankId, retryable: false }) });
    }
    return route.fulfill({ status: 404 });
  });
  return mutations;
}

function projection() {
  const prize = { name: "Copied prize", presentation_asset_id: imageId, rank_id: rankId, exchange_points: 10, cost_price: 0, initial_inventory: 3, shipping_only: false };
  const asset = { id: imageId, path: "/synthetic-login-image.png", mime_type: "image/png", alt_text: "Frozen image" };
  return { gacha_type: "login_daily", title: "Published login fixture", description: null, notices: null, presentation_asset_id: imageId,
    price_points: 0, minimum_exchange_points: 10, publish_start_at: null, publish_end_at: null, category_id: null, tag_ids: [], total_count: null,
    daily_draw_limit: 1, audience_code: "all_users", first_time_eligible_days: 7, allowed_draw_counts: [1],
    ranks: [{ rank_id: rankId, rank_revision_number: 1, video_asset_id: videoId, presentation: { name: "Frozen A", lineup_image: asset, result_image: asset } }],
    prizes: [{ ...prize, percentage: "0.0000000001" }, { ...prize, percentage: "99.9999999999" }] };
}

function json(route: Route, body: unknown) {
  return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(body) });
}
