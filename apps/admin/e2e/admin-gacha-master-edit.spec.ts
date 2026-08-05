import { expect, test, type Page, type Route } from "@playwright/test";

const gachaCode = "A7k9P2x4Qm8";
const gachaUuid = uuid("1");
const versionId = uuid("2");
const categoryId = uuid("3");
const tagId = uuid("4");
const assetId = uuid("5");
const uploadedAssetId = uuid("6");
const rankId = uuid("7");
const prizeId = uuid("8");
const csrf = "a".repeat(64);

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, csrf);
  await installApi(page);
});

test("canonical gacha edit uploads a file without banner dependencies", async ({ page }) => {
  const requests: string[] = [];
  page.on("request", (request) => requests.push(new URL(request.url()).pathname));

  const detail = await page.goto(`/catalog/gachas/${gachaCode}`);
  expect(detail?.status()).toBe(200);
  await expect(page.getByText(gachaCode, { exact: true })).toHaveText(gachaCode);
  await expect(page.getByText(gachaUuid, { exact: true })).toHaveCount(0);
  await expect(page.getByRole("columnheader", { name: "ID", exact: true })).toHaveCount(0);

  await page.getByRole("link", { name: "Master編集" }).click();
  await expect(page).toHaveURL(`/gachas/${gachaCode}/edit`);
  await expect(page.getByRole("heading", { level: 1, name: "ガチャ編集" })).toBeVisible();
  await expect(page.getByLabel("ガチャタイトル")).toHaveValue("編集対象ガチャ");
  await expect(page.getByLabel(/サムネイル画像/u)).toBeVisible();
  await expect(page.getByRole("img", { name: "現在のサムネイル" })).toBeVisible();

  await page.getByLabel(/サムネイル画像/u).setInputFiles({
    buffer: Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
      "base64",
    ),
    mimeType: "image/png",
    name: "replacement.png",
  });
  await expect(page.getByRole("img", { name: "選択したサムネイルのPreview" }))
    .toBeVisible();
  await page.getByLabel("ガチャタイトル").fill("更新後ガチャ");
  await page.getByRole("button", { name: "編集Draftへ保存" }).click();
  await expect(page).toHaveURL(`/catalog/gachas/${gachaCode}`);

  expect(requests.some((path) => path.endsWith("/catalog/gacha-thumbnails"))).toBe(true);
  expect(requests.some((path) => path.startsWith("/admin/api/v2/banner-management")))
    .toBe(false);
});

test("mobile master edit remains usable without horizontal overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto(`/gachas/${gachaCode}/edit`);
  await expect(page.getByRole("heading", { level: 1, name: "ガチャ編集" })).toBeVisible();
  await page.getByLabel("ガチャタイトル").focus();
  await expect(page.getByLabel("ガチャタイトル")).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("9"), mfa_verified: false, role: "owner", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ["catalog.read", "catalog.manage"],
        request_id: uuid("9"),
        role: "owner",
      });
    }
    if (path.endsWith(`/catalog/presentation-assets/${assetId}/content`)) {
      return route.fulfill({
        body: Buffer.from(
          "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
          "base64",
        ),
        contentType: "image/png",
        status: 200,
      });
    }
    if (path.endsWith("/catalog/gacha-thumbnails") && request.method() === "POST") {
      const body = request.postDataJSON();
      expect(body.file_name).toBe("replacement.png");
      expect(body.mime_type).toBe("image/png");
      expect(body.content_base64).toBeTruthy();
      return json(route, {
        data: { id: uploadedAssetId },
        idempotent_replay: false,
        request_id: uuid("9"),
      }, 201);
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}`) && request.method() === "PUT") {
      const body = request.postDataJSON();
      expect(body.presentation_asset_id).toBe(uploadedAssetId);
      expect(body.title).toBe("更新後ガチャ");
      expect(body.expected_revision).toBe(3);
      expect(body.expected_version_revision).toBe(4);
      return json(route, {
        data: { ...gacha(), current_version: { ...gacha().current_version, title: body.title } },
        idempotent_replay: false,
        request_id: uuid("9"),
      });
    }
    if (path.endsWith("/catalog/categories")) {
      return json(route, { items: [category()], next_cursor: null });
    }
    if (path.endsWith("/catalog/tags")) {
      return json(route, { items: [tag()], next_cursor: null });
    }
    if (path.endsWith("/catalog/presentation-assets")) {
      return json(route, { items: [], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions/${versionId}/ranks`)) {
      return json(route, { items: [rank()], version_revision: 4 });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions/${versionId}/prizes`)) {
      return json(route, { items: [prize()], version_revision: 4 });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions`)) {
      return json(route, { items: [gacha().current_version], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}`)) {
      return json(route, { data: gacha() });
    }
    return route.fulfill({ status: 404 });
  });
}

function gacha() {
  return {
    archived_at: null,
    category: { code: "cards", id: categoryId, name: "カード" },
    code: "gacha-internal-code",
    created_at: "2026-08-01T00:00:00Z",
    current_version: {
      audience_code: "all_users",
      daily_draw_limit: 0,
      description: "説明",
      id: versionId,
      notices: "注意事項",
      presentation_asset: {
        alt_text: "現在のサムネイル",
        id: assetId,
        is_public: true,
        media_type: "image",
        mime_type: "image/png",
        public_path: `/admin/api/v2/catalog/presentation-assets/${assetId}/content`,
      },
      price_points: 100,
      publish_end_at: null,
      publish_start_at: "2026-08-20T00:00:00Z",
      revision: 4,
      status: "draft",
      title: "編集対象ガチャ",
      total_count: 100,
      version_number: 2,
    },
    has_draw_history: false,
    id: gachaUuid,
    is_archived: false,
    public_code: gachaCode,
    published_version: null,
    revision: 3,
    slug: "gacha-internal-code",
    sold_count: 0,
    state: "draft",
    tags: [{ code: "featured", id: tagId, name: "Featured" }],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}

function category() {
  return {
    code: "cards", created_at: "2026-08-01T00:00:00Z", description: null,
    id: categoryId, is_archived: false, is_visible: true, name: "カード",
    revision: 1, slug: "cards", sort_order: 1, updated_at: "2026-08-01T00:00:00Z",
  };
}

function tag() {
  return {
    code: "featured", created_at: "2026-08-01T00:00:00Z", description: null,
    id: tagId, is_archived: false, is_visible: true, name: "Featured",
    revision: 1, slug: "featured", sort_order: 1, updated_at: "2026-08-01T00:00:00Z",
  };
}

function rank() {
  return {
    code: "S", created_at: "2026-08-01T00:00:00Z", description: null,
    id: rankId, image_asset: null, is_archived: false, name: "S",
    revision: 1, sort_order: 1, updated_at: "2026-08-01T00:00:00Z", video_asset: null,
  };
}

function prize() {
  return {
    available_inventory: 10, code: "prize-s", cost_price: 500,
    created_at: "2026-08-01T00:00:00Z", exchange_points: 1000,
    id: prizeId, is_active: true, name: "景品S", presentation_asset: null,
    rank: { code: "S", id: rankId, name: "S", sort_order: 1 },
    revision: 1, total_inventory: 10, updated_at: "2026-08-01T00:00:00Z",
  };
}

function uuid(seed: string): string {
  return `01910191-0191-7191-8191-01910191019${seed}`;
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({ body: JSON.stringify(body), contentType: "application/json", status });
}
