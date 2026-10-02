import { expect, test, type Page, type Route } from "@playwright/test";

const gachaId = "A7k9P2x4Qm8";
const rankId = "0198a001-0000-7000-8000-000000000001";
const imageId = "0198a001-0000-7000-8000-000000000005";
const videoId = "0198a001-0000-7000-8000-000000000006";
const bannerCategoryId = "0198a001-0000-7000-8000-000000000011";
const otherBannerCategoryId = "0198a001-0000-7000-8000-000000000012";
const otherBannerAssetId = "0198a001-0000-7000-8000-000000000013";

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
  await selectThumbnail(page);
  await page.getByLabel("公開開始日時（JST）").fill("2026-10-01T00:00");
  await page.getByRole("button", { name: "景品を追加" }).click();
  await page.getByLabel("景品名", { exact: true }).fill("Prize fixture");
  await page.getByRole("combobox", { name: "Banner Category", exact: true }).selectOption(bannerCategoryId);
  await page.getByRole("button", { name: "Card Banner", exact: true }).click();
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
  await page.route(/\/synthetic-(?:banner|login)-image\.png$/u, (route) => route.fulfill({ contentType: "image/png", body: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64") }));
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, { authenticated: true, mfa_required: false, requires_mfa_enrollment: false,
      admin: { id: rankId, role: "owner", state: "active", mfa_verified: true } });
    if (path.endsWith("/auth/permissions")) return json(route, { role: "owner", request_id: rankId, permissions: ["catalog.read", "catalog.manage", "catalog.publish"] });
    if (path.endsWith("/banner-management/categories")) return json(route, { items: [
      { id: bannerCategoryId, name: "Cards" }, { id: otherBannerCategoryId, name: "Other" }, { id: "empty", name: "Empty" },
    ] });
    if (path.endsWith("/banner-management/banners")) {
      const categoryId = new URL(route.request().url()).searchParams.get("category_id");
      expect([bannerCategoryId, otherBannerCategoryId, "empty"]).toContain(categoryId);
      return json(route, { items: categoryId === "empty" ? [] : [{ id: `banner-${categoryId}`, title: categoryId === bannerCategoryId ? "Card Banner" : "Other Banner",
        category: { id: categoryId, name: categoryId === bannerCategoryId ? "Cards" : "Other" },
        asset: { id: categoryId === bannerCategoryId ? imageId : otherBannerAssetId, public_url: "/synthetic-banner-image.png" },
      }], next_cursor: null });
    }
    if (path.endsWith("/catalog/categories") || path.endsWith("/catalog/tags")) return json(route, { items: [], next_cursor: null });
    if (path.endsWith("/catalog/gachas")) return json(route, { items: [], next_cursor: null });
    if (path.endsWith("/catalog/ranks")) return json(route, { items: [{ id: rankId, rank_name: "A", status: "active" }], next_cursor: null });
    if (path.endsWith("/catalog/presentation-assets")) return json(route, { items: [
      { id: imageId, media_type: "image", mime_type: "image/png", is_public: true, public_path: null, alt_text: "Image fixture" },
      { id: videoId, media_type: "video", mime_type: "video/mp4", is_public: true, public_path: null, alt_text: "Video fixture" },
    ], next_cursor: null });
    if (path.endsWith("/catalog/gacha-thumbnails") && route.request().method() === "POST") {
      return json(route, { data: { id: imageId, media_type: "image", mime_type: "image/png", is_public: true, public_path: "/synthetic-login-image.png", alt_text: "QA thumbnail" } });
    }
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

function projection(type = "login_daily") {
  const prize = { name: "Copied prize", presentation_asset_id: imageId, rank_id: rankId, exchange_points: 10, cost_price: 0, initial_inventory: 3, shipping_only: false };
  const asset = { id: imageId, path: "/synthetic-login-image.png", mime_type: "image/png", alt_text: "Frozen image" };
  return { gacha_type: type, title: "Published login fixture", description: null, notices: null, presentation_asset_id: imageId,
    price_points: 0, minimum_exchange_points: type === "login_daily" ? 10 : null, publish_start_at: null, publish_end_at: null, category_id: null, tag_ids: [], total_count: null,
    daily_draw_limit: type === "login_daily" ? 1 : 0, audience_code: "all_users", first_time_eligible_days: 7, allowed_draw_counts: [1],
    ranks: [{ rank_id: rankId, rank_revision_number: 1, video_asset_id: videoId, presentation: { name: "Frozen A", lineup_image: asset, result_image: asset } }],
    prizes: [{ ...prize, percentage: "0.0000000001" }, { ...prize, percentage: "99.9999999999" }] };
}

function json(route: Route, body: unknown) {
  return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(body) });
}

async function selectThumbnail(page: Page) {
  const encoded = await page.evaluate(() => {
    const canvas = document.createElement("canvas");
    canvas.width = 64; canvas.height = 36;
    return canvas.toDataURL("image/png").split(",")[1];
  });
  await page.getByLabel(/サムネイル画像/u).setInputFiles({ name: "qa-login-thumbnail.png", mimeType: "image/png", buffer: Buffer.from(encoded, "base64") });
  await expect(page.getByRole("img", { name: "選択したサムネイルのPreview" })).toBeVisible();
  return encoded;
}

for (const width of [1440, 390]) {
  for (const type of ["login", "signup"]) {
    test(`${type} ${width}px shares standard card and upload layout without clipping or page errors`, async ({ page }, testInfo) => {
      const errors: string[] = [];
      page.on("pageerror", (error) => errors.push(error.message));
      page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
      await page.setViewportSize({ width, height: 900 });
      await installApi(page);
      await page.goto("/catalog/gachas/new/standard");
      const standard = await page.locator(".catalog-core-form-card").evaluate((element) => {
        const style = getComputedStyle(element);
        return { background: style.backgroundColor, padding: style.padding, border: style.border, gap: style.gap };
      });
      const standardInput = await page.getByLabel("ガチャタイトル").evaluate((element) => getComputedStyle(element).height);
      await page.goto(`/catalog/gachas/new/${type}`);
      await expect(page.getByLabel("ガチャ名", { exact: true })).toBeVisible();
      expect(await page.locator(".catalog-core-form-card").evaluate((element) => {
        const style = getComputedStyle(element);
        return { background: style.backgroundColor, padding: style.padding, border: style.border, gap: style.gap };
      })).toEqual(standard);
      expect(standard.background).toBe("rgb(255, 255, 255)");
      expect(await page.getByLabel("ガチャ名", { exact: true }).evaluate((element) => getComputedStyle(element).height)).toBe(standardInput);
      await expect(page.getByRole("img", { name: "Previewなし" })).toBeVisible();
      await selectThumbnail(page);
      await expect(page.getByLabel(/サムネイル画像/u)).toHaveValue(/qa-login-thumbnail.png$/u);
      await page.getByRole("button", { name: "景品を追加" }).click();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      const clipped = await page.locator(".catalog-core-form-card input, .catalog-core-form-card select, .catalog-core-form-card textarea, .catalog-dialog-actions button").evaluateAll((elements) => elements.some((element) => {
        const box = element.getBoundingClientRect();
        return box.width === 0 || box.left < 0 || box.right > innerWidth;
      }));
      expect(clipped).toBe(false);
      const cancel = await page.getByRole("button", { name: "取り消し", exact: true }).boundingBox();
      const save = await page.getByRole("button", { name: "構成を一括保存" }).boundingBox();
      expect(cancel && save && (cancel.x + cancel.width <= save.x || cancel.y + cancel.height <= save.y)).toBe(true);
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: testInfo.outputPath(`${type}-${width}.png`), fullPage: true });
      expect(errors).toEqual([]);
    });
  }
}

for (const type of ["login_daily", "signup_once"]) {
  for (const width of [1440, 390]) {
    test(`${type} ${width}px prize uses the standard category-scoped Banner picker without thumbnail changes`, async ({ page }, testInfo) => {
      await installApi(page);
      await page.setViewportSize({ width, height: 900 });
      const errors: string[] = [];
      const saved: Record<string, unknown>[] = [];
      const uploads: string[] = [];
      page.on("pageerror", (error) => errors.push(error.message));
      page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
      page.on("request", (request) => { if (request.url().endsWith("/catalog/gacha-thumbnails")) uploads.push(request.url()); });
      const source = projection(type);
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}/copy`, (route) => json(route, { data: source }));
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}/composition`, (route) => json(route, { data: source }));
      await page.route("**/admin/api/v2/catalog/gacha-compositions", (route) => {
        saved.push(route.request().postDataJSON() as Record<string, unknown>);
        return json(route, { data: { id: rankId, public_code: gachaId } });
      });
      await page.goto(`/catalog/gachas/${gachaId}/copy`);
      const prize = page.locator(".catalog-prize-fieldset").first();
      const category = prize.getByRole("combobox", { name: "Banner Category", exact: true });
      await expect(category).toHaveValue(bannerCategoryId);
      await expect(prize.getByRole("button", { name: "Card Banner", exact: true })).toHaveAttribute("aria-pressed", "true");
      await expect(page.getByLabel(/サムネイル画像/u)).toHaveAttribute("type", "file");
      await expect(page.getByLabel("景品画像", { exact: true })).toHaveCount(0);
      await page.getByLabel("公開開始日時（JST）").fill("2026-10-02T00:00");
      await category.selectOption("empty");
      await expect(prize.getByText("このCategoryに選択可能なBannerはありません。")).toBeVisible();
      await page.getByRole("button", { name: "構成を一括保存" }).click();
      await expect(page.getByRole("alert").filter({ hasText: "選択したBanner Category" })).toBeVisible();
      expect(saved).toHaveLength(0);
      await category.selectOption(otherBannerCategoryId);
      await expect(prize.getByRole("button", { name: "Card Banner", exact: true })).toHaveCount(0);
      const banner = prize.getByRole("button", { name: "Other Banner", exact: true });
      await expect(banner.locator("img")).toBeVisible();
      await banner.click();
      await expect(banner).toHaveAttribute("aria-pressed", "true");
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await prize.screenshot({ path: testInfo.outputPath(`prize-${type}-${width}.png`) });
      await page.getByRole("button", { name: "構成を一括保存" }).click();
      await expect.poll(() => saved.length).toBe(1);
      expect(saved[0]).toMatchObject({ gacha_type: type, presentation_asset_id: imageId, prizes: [
        { presentation_asset_id: otherBannerAssetId, percentage: "0.0000000001" },
        { presentation_asset_id: imageId, percentage: "99.9999999999" },
      ] });
      expect(uploads).toHaveLength(0);
      expect(errors).toEqual([]);
    });
  }

  for (const replace of [false, true]) {
    test(`${type} copy ${replace ? "replaces" : "reuses"} the canonical thumbnail through a successful save`, async ({ page }) => {
      await installApi(page);
      const errors: string[] = [];
      const uploads: Record<string, unknown>[] = [];
      const saved: Record<string, unknown>[] = [];
      page.on("pageerror", (error) => errors.push(error.message));
      page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
      const replacementId = "0198a001-0000-7000-8000-000000000009";
      const source = projection(type);
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}/copy`, (route) => json(route, { data: source }));
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}/composition`, (route) => json(route, { data: saved[0] ?? source }));
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}`, (route) => json(route, { data: { id: rankId, public_code: gachaId, gacha_type: type,
        publication_status: "draft", first_published_at: null, revision: 1 } }));
      await page.route("**/admin/api/v2/catalog/presentation-assets?*", (route) => json(route, { items: [
        ...[imageId, replacementId].map((identifier) => ({ id: identifier, media_type: "image", mime_type: "image/png", is_public: true, public_path: "/synthetic-login-image.png", alt_text: "QA thumbnail" })),
        { id: videoId, media_type: "video", mime_type: "video/mp4", is_public: true, public_path: null, alt_text: "QA video" },
      ], next_cursor: null }));
      await page.route("**/synthetic-login-image.png", (route) => route.fulfill({ contentType: "image/png", body: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64") }));
      await page.route("**/admin/api/v2/catalog/gacha-thumbnails", (route) => {
        uploads.push(route.request().postDataJSON() as Record<string, unknown>);
        expect(route.request().headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
        return json(route, { data: { id: replacementId } });
      });
      await page.route("**/admin/api/v2/catalog/gacha-compositions", (route) => {
        saved.push(route.request().postDataJSON() as Record<string, unknown>);
        return json(route, { data: { id: rankId, public_code: gachaId } });
      });
      await page.goto(`/catalog/gachas/${gachaId}/copy`);
      await expect(page.getByLabel(/サムネイル画像/u)).toBeEnabled();
      await expect(page.locator(".catalog-thumbnail-field img")).toBeVisible();
      expect(saved).toHaveLength(0);
      expect(uploads).toHaveLength(0);
      if (replace) await selectThumbnail(page);
      await page.getByLabel("公開開始日時（JST）").fill("2026-10-02T00:00");
      await page.getByRole("button", { name: "構成を一括保存" }).click();
      await expect(page).toHaveURL(`/catalog/gachas/${gachaId}`);
      await expect(page.locator(".catalog-thumbnail-field img")).toBeVisible();
      expect(saved).toHaveLength(1);
      expect(saved[0]).toMatchObject({ gacha_type: type, presentation_asset_id: replace ? replacementId : imageId });
      expect(saved[0]).not.toHaveProperty("content_base64");
      expect(uploads).toHaveLength(replace ? 1 : 0);
      expect(errors).toEqual([]);
    });
  }

  test(`${type} displays invalid thumbnail validation before upload`, async ({ page }) => {
    const mutations = await installApi(page);
    const uploads: string[] = [];
    page.on("request", (request) => { if (request.url().endsWith("/catalog/gacha-thumbnails")) uploads.push(request.url()); });
    await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}/copy`, (route) => json(route, { data: projection(type) }));
    await page.goto(`/catalog/gachas/${gachaId}/copy`);
    await page.getByLabel("公開開始日時（JST）").fill("2026-10-02T00:00");
    await page.getByLabel(/サムネイル画像/u).setInputFiles({ name: "qa-invalid.svg", mimeType: "image/svg+xml", buffer: Buffer.from("invalid") });
    await page.getByRole("button", { name: "構成を一括保存" }).click();
    await expect(page.getByRole("alert").filter({ hasText: "サムネイルはGIF" })).toHaveText("サムネイルはGIF、JPEG、PNG、WebPの5 MB以下にしてください。");
    expect(uploads).toHaveLength(0);
    expect(mutations).toHaveLength(0);
  });

  for (const status of ["draft", "published", "sales_paused"]) {
    test(`${type} ${status} shows current thumbnail and preserves immutable upload boundaries`, async ({ page }) => {
      await installApi(page);
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}`, (route) => json(route, { data: { id: rankId, public_code: gachaId, gacha_type: type,
        publication_status: status, first_published_at: status === "draft" ? null : "2026-10-01T00:00:00Z", revision: 1 } }));
      await page.route(`**/admin/api/v2/catalog/gachas/${gachaId}/composition`, (route) => json(route, { data: projection(type) }));
      await page.route("**/catalog/presentation-assets?*", (route) => json(route, { items: [
        { id: imageId, media_type: "image", mime_type: "image/png", is_public: true, public_path: "/synthetic-login-image.png", alt_text: "Current QA thumbnail" },
        { id: videoId, media_type: "video", mime_type: "video/mp4", is_public: true, public_path: null, alt_text: "Video fixture" },
      ], next_cursor: null }));
      await page.route("**/synthetic-login-image.png", (route) => route.fulfill({ contentType: "image/png", body: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6XikAAAAASUVORK5CYII=", "base64") }));
      await page.goto(`/catalog/gachas/${gachaId}`);
      const input = page.getByLabel(/サムネイル画像/u);
      await expect(page.getByRole("img", { name: "Current QA thumbnail" }).first()).toBeVisible();
      if (status === "draft") {
        await expect(input).toBeEnabled();
        await selectThumbnail(page);
      } else {
        await expect(input).toBeDisabled();
        await expect(page.getByRole("button", { name: "構成を一括保存" })).toHaveCount(0);
      }
    });
  }
}
