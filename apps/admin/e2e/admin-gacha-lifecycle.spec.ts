import { expect, test, type Page, type Route } from "@playwright/test";

const gachaCode = "A7k9P2x4Qm8";
const categoryId = uuid("1");
const tagId = uuid("2");
const assetId = uuid("3");
const versionId = uuid("4");

test.beforeEach(async ({ page }) => installApi(page));

test("published Gacha edit exposes only the lifecycle whitelist", async ({ page }) => {
  const response = await page.goto(`/gachas/${gachaCode}/edit`);
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { level: 1, name: "ガチャ編集" }))
    .toBeVisible();

  await expect(page.getByLabel("カテゴリ")).toBeDisabled();
  await expect(page.getByLabel("消費ポイント")).toBeDisabled();
  await expect(page.getByLabel("総口数")).toBeDisabled();
  await expect(page.getByLabel(/1日規定回数/u)).toBeDisabled();
  await expect(page.getByLabel("会員ランク")).toBeDisabled();
  await expect(page.getByLabel("開始日時（Asia/Tokyo）")).toBeDisabled();
  await expect(page.getByLabel("ガチャタイトル")).toBeEnabled();
  await expect(page.getByLabel("終了日時（Asia/Tokyo）")).toBeEnabled();
  await expect(page.getByLabel("状態").getByRole("option", { name: "販売停止" }))
    .toHaveCount(1);
  await expect(page.getByLabel("状態").getByRole("option", { name: "予約公開" }))
    .toHaveCount(0);
});

test("published Gacha lifecycle edit has no mobile overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto(`/gachas/${gachaCode}/edit`);
  await expect(page.getByRole("heading", { level: 1, name: "ガチャ編集" }))
    .toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("9"), mfa_verified: true, role: "owner", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ["catalog.read", "catalog.manage", "catalog.publish"],
        request_id: uuid("9"),
        role: "owner",
      });
    }
    if (path.endsWith("/catalog/categories")) {
      return json(route, { items: [category()], next_cursor: null });
    }
    if (path.endsWith("/catalog/tags")) {
      return json(route, { items: [tag()], next_cursor: null });
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
      allowed_draw_counts: [1, 5, 10],
      audience_code: "all_users",
      daily_draw_limit: 0,
      description: "説明",
      first_time_eligible_days: 7,
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
      publish_end_at: "2027-08-20T00:00:00Z",
      publish_start_at: "2026-08-01T00:00:00Z",
      revision: 4,
      status: "published",
      title: "公開中ガチャ",
      total_count: 100,
      version_number: 1,
    },
    first_published_at: "2026-08-01T00:00:00Z",
    has_draw_history: true,
    id: uuid("5"),
    is_archived: false,
    public_code: gachaCode,
    publication_status: "published",
    published_version: { id: versionId, title: "公開中ガチャ", version_number: 1 },
    revision: 3,
    slug: "gacha-internal-code",
    sold_count: 25,
    state: "active",
    tags: [{ code: "featured", id: tagId, name: "Featured" }],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}

function category() {
  return {
    code: "cards",
    created_at: "2026-08-01T00:00:00Z",
    description: null,
    id: categoryId,
    is_archived: false,
    is_visible: true,
    name: "カード",
    revision: 1,
    slug: "cards",
    sort_order: 1,
    updated_at: "2026-08-01T00:00:00Z",
  };
}

function tag() {
  return {
    code: "featured",
    created_at: "2026-08-01T00:00:00Z",
    description: null,
    id: tagId,
    is_archived: false,
    is_visible: true,
    name: "Featured",
    revision: 1,
    slug: "featured",
    sort_order: 1,
    updated_at: "2026-08-01T00:00:00Z",
  };
}

function uuid(seed: string): string {
  return `01910191-0191-7191-8191-01910191019${seed}`;
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({ body: JSON.stringify(body), contentType: "application/json", status });
}
