import { expect, test, type Page } from "@playwright/test";

const identity = "0198a001-0000-7000-8000-000000000001";
const imageId = "0198a001-0000-7000-8000-000000000005";
const videoId = "0198a001-0000-7000-8000-000000000006";
const code = "A7k9P2x4Qm8";
const videoBytes = Buffer.from("GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQRChYECGFOAZwEAAAAAAAEyEU2bdLlNu4tTq4QVSalmU6yBbk27i1OrhBZUrmtTrIGTTbuLU6uEH0O2dVOsgcFNu4xTq4QcU7trU6yCASDsrgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmoCrXsYMPQkBEiYQ/gAAATYCGQ2hyb21lV0GGQ2hyb21lFlSua6mup9eBAXPFh8JblnZHhQ+DgQFV7oEBhoVWX1ZQOOCKsIEQuoEQU8CBAR9DtnUBAAAAAAAAU+eBAKDOoaOBAAAAEAIAnQEqEAAQAAAHCIWFiJmEiAEkEABgawD+/6tQgHWhpqak7oEBpZ8QAgCdASoQABAAAAcIhYWImYSIASQQAGBrAP7/uoMAHFO7a427i7OBALeG94EB8YHB", "base64");
const png = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64");

for (const width of [1440, 390]) {
  for (const type of ["standard", "login_daily", "signup_once"] as const) {
    test(`notice settings and ${type} blank form isolation at ${width}px`, async ({ page }, testInfo) => {
      const state = await installApi(page, type);
      const errors: string[] = [];
      page.on("pageerror", (error) => errors.push(error.message));
      page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
      await page.setViewportSize({ width, height: 900 });
      await page.goto("/settings/gacha-notices");
      await expect(page.getByRole("heading", { name: "ガチャ注意事項設定", exact: true })).toBeVisible();
      await page.getByLabel("通常ガチャのデフォルト注意事項", { exact: true }).fill("Standard A");
      await page.getByLabel("ログイン・新規登録限定ガチャのデフォルト注意事項", { exact: true }).fill("Login A");
      await page.getByRole("button", { name: "保存", exact: true }).click();
      await expect(page.getByText("設定を保存しました。")).toBeVisible();
      await page.getByRole("button", { name: "保存", exact: true }).click();
      await expect.poll(() => state.saves).toBe(2);
      await page.screenshot({ path: testInfo.outputPath("notice-settings.png"), fullPage: true });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      const route = type === "standard" ? "standard" : type === "login_daily" ? "login" : "signup";
      await page.goto(`/catalog/gachas/new/${route}`);
      await expect(page.getByRole("textbox", { name: "注意事項", exact: true })).toHaveValue(type === "standard" ? "Standard A" : "Login A");
      await page.screenshot({ path: testInfo.outputPath("notice-blank-new.png"), fullPage: true });
      state.defaults.standard.default_notices = "Standard B";
      state.defaults.login.default_notices = "Login B";
      state.defaults.standard.revision += 1;
      state.defaults.login.revision += 1;
      await expect(page.getByRole("textbox", { name: "注意事項", exact: true })).toHaveValue(type === "standard" ? "Standard A" : "Login A");
      await page.getByRole("textbox", { name: "注意事項", exact: true }).fill("Individual QA notice");
      await page.getByLabel(type === "standard" ? "ガチャタイトル" : "ガチャ名", { exact: true }).fill("Notice QA");
      await page.getByLabel(/サムネイル画像/u).setInputFiles({ name: "qa.png", mimeType: "image/png", buffer: png });
      if (type === "standard") {
        await page.getByRole("combobox", { name: "カテゴリ", exact: true }).selectOption(identity);
        await page.getByLabel("開始日時（Asia/Tokyo）").fill("2026-10-06T00:00");
      } else {
        await page.getByLabel("公開開始日時（JST）").fill("2026-10-06T00:00");
        await page.getByRole("button", { name: "景品を追加" }).click();
        await page.getByLabel("景品名", { exact: true }).fill("QA prize");
        await page.getByRole("combobox", { name: "Banner Category", exact: true }).selectOption(identity);
        await page.getByRole("button", { name: "QA Banner", exact: true }).click();
        await page.getByRole("combobox", { name: "QAの演出動画", exact: true }).selectOption(videoId);
        await page.getByLabel("固定当選確率（%・小数10桁まで）").fill("100");
      }
      await page.getByRole("button", { name: type === "standard" ? "下書きを登録" : "構成を一括保存", exact: true }).click();
      await expect.poll(() => state.saved?.notices).toBe("Individual QA notice");
      await page.waitForURL(`**/catalog/gachas/${code}`);
      expect(state.defaults.standard.default_notices).toBe("Standard B");
      expect(state.defaults.login.default_notices).toBe("Login B");
      await page.goto(`/catalog/gachas/new/${route}`);
      await expect(page.getByRole("textbox", { name: "注意事項", exact: true })).toHaveValue(type === "standard" ? "Standard B" : "Login B");
      await page.goto(`/catalog/gachas/${code}/copy`);
      await expect(page.getByRole("textbox", { name: "注意事項", exact: true })).toHaveValue("Individual QA notice");
      await page.screenshot({ path: testInfo.outputPath("notice-copy.png"), fullPage: true });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      expect(errors).toEqual([]);
    });
  }
}

async function installApi(page: Page, type: "standard" | "login_daily" | "signup_once") {
  const state = { defaults: { standard: { default_notices: "", revision: 1 }, login: { default_notices: "", revision: 1 } }, saves: 0, saved: null as Record<string, unknown> | null };
  const asset = { id: imageId, media_type: "image", mime_type: "image/png", is_public: true, public_path: "/qa-notice.png", alt_text: "QA thumbnail" };
  const category = { id: identity, code: "qa", name: "QA category", is_archived: false };
  const gacha = () => ({ id: identity, public_code: code, code: "qa", slug: "qa", gacha_type: type, state: "active", category: type === "standard" ? category : null,
    tags: [], published_version: null, version_count: 1, sold_count: 0, has_draw_history: false, is_archived: false, revision: 1,
    archived_at: null, created_at: "2026-10-05T00:00:00Z", updated_at: "2026-10-05T00:00:00Z", publication_status: "draft", first_published_at: null,
    current_version: { ...state.saved, id: identity, version_number: 1, revision: 1, status: "draft", presentation_asset: asset } });
  await page.addInitScript(() => { Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined }); });
  await page.route("**/qa-notice.png", (route) => route.fulfill({ contentType: "image/png", body: png }));
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const json = (body: unknown) => route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(body) });
    if (path.endsWith("/auth/session")) return json({ authenticated: true, mfa_required: false, requires_mfa_enrollment: false, admin: { id: identity, role: "owner", state: "active", mfa_verified: true } });
    if (path.endsWith("/auth/permissions")) return json({ role: "owner", permissions: ["catalog.read", "catalog.manage", "catalog.publish"], request_id: identity });
    if (path.endsWith("/settings/gacha-notices")) {
      if (request.method() === "PUT") {
        const body = request.postDataJSON();
        expect(body.standard.expected_revision).toBe(state.defaults.standard.revision);
        expect(body.login.expected_revision).toBe(state.defaults.login.revision);
        for (const scope of ["standard", "login"] as const) state.defaults[scope] = { default_notices: body[scope].default_notices, revision: state.defaults[scope].revision + 1 };
        state.saves += 1;
      }
      return json({ data: state.defaults, request_id: identity, idempotent_replay: false });
    }
    if (path.endsWith("/catalog/categories")) return json({ items: [category], next_cursor: null });
    if (path.endsWith("/catalog/gachas")) return json({ items: state.saved ? [gacha()] : [], next_cursor: null });
    if (path.endsWith("/catalog/tags") || path.endsWith("/catalog/rank-effects")) return json({ items: [], next_cursor: null });
    if (path.endsWith("/catalog/ranks")) return json({ items: [{ id: identity, rank_name: "QA", status: "active" }], next_cursor: null });
    if (path.endsWith(`/catalog/presentation-assets/${videoId}/content`)) return route.fulfill({ contentType: "video/webm", body: videoBytes });
    if (path.endsWith("/catalog/presentation-assets")) return json({ items: [asset, { id: videoId, media_type: "video", mime_type: "video/webm", is_public: true, public_path: null, alt_text: "QA video" }], next_cursor: null });
    if (path.endsWith("/catalog/gacha-thumbnails")) return json({ data: asset, idempotent_replay: false });
    if (path.endsWith("/banner-management/categories")) return json({ items: [category] });
    if (path.endsWith("/banner-management/banners")) return json({ items: [{ id: identity, title: "QA Banner", category, asset: { id: imageId, public_url: "/qa-notice.png" } }], next_cursor: null });
    if (path.endsWith("/catalog/gachas/core") || path.endsWith("/catalog/gacha-compositions")) {
      state.saved = request.postDataJSON() as Record<string, unknown>;
      expect(request.headers()["idempotency-key"]).toBeTruthy();
      return json({ data: gacha(), idempotent_replay: false });
    }
    if (path.endsWith(`/catalog/gachas/${code}`)) return json({ data: gacha() });
    if (path.endsWith("/copy") || path.endsWith("/composition")) return json({ data: { ...state.saved, gacha_type: type,
      ranks: [{ rank_id: identity, rank_revision_number: null, video_asset_id: videoId }],
      prizes: state.saved?.prizes ?? [], publish_start_at: path.endsWith("/copy") ? null : state.saved?.publish_start_at,
    } });
    if (path.endsWith("/prizes")) return json({ items: [], version_revision: 1, next_cursor: null });
    if (path.includes("/catalog/gachas/")) return json({ items: [], next_cursor: null, data: {} });
    return route.fulfill({ status: 404 });
  });
  return state;
}
