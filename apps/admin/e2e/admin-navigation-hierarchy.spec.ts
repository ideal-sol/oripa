import { expect, test, type Page, type Route } from "@playwright/test";

const gachaId = "01910191-0191-7191-8191-019101910210";
const categoryId = "01910191-0191-7191-8191-019101910211";
const tagId = "01910191-0191-7191-8191-019101910212";
const assetId = "01910191-0191-7191-8191-019101910213";
const versionId = "01910191-0191-7191-8191-019101910214";
const rankId = "01910191-0191-7191-8191-019101910215";
const prizeId = "01910191-0191-7191-8191-019101910216";

const ownerPermissions = [
  "identity.admin.read",
  "identity.admin.manage",
  "identity.admin.session.revoke",
  "identity.line.read",
  "identity.line.manage",
  "point.ledger.read",
  "point.adjustment.request",
  "point.adjustment.free.approve",
  "point.adjustment.paid.approve",
  "payment.plan.read",
  "payment.plan.manage",
  "catalog.read",
  "catalog.manage",
  "catalog.publish",
  "shipping.request.manage",
  "qa.draw.manage",
  "reporting.financial.read",
  "reporting.financial.export",
  "content.read",
  "content.manage",
  "content.publish",
  "contact.read",
  "contact.manage",
];

const groups = [
  ["ユーザー", ["/users", "/users/history"]],
  ["ガチャ", [
    "/catalog/gachas",
    "/catalog/gachas/new",
    "/catalog/gachas/simulation",
    "/catalog/categories",
    "/catalog/tags",
    "/catalog/gachas/history",
  ]],
  ["決済", ["/payments"]],
  ["配送", ["/shipping"]],
  ["ポイント購入", ["/purchase-plans", "/purchase-plans/new"]],
  ["お知らせ", ["/announcements", "/announcements/new"]],
  ["バナー", ["/banners", "/banners/new"]],
  ["お問い合わせ", ["/contacts"]],
  ["各種設定", [
    "/settings/pages",
    "/catalog/presentation-assets",
    "/settings/referral",
    "/settings/line",
  ]],
] as const;

test.beforeEach(async ({ page }) => {
  await installPreviewApi(page);
});

test("Owner navigates the approved hierarchy and every route returns 200", async ({
  page,
}) => {
  const consoleErrors: string[] = [];
  page.on("console", (message) => {
    if (message.type() === "error") consoleErrors.push(message.text());
  });

  await page.goto("/");
  const navigation = page.getByRole("navigation", { name: "管理ナビゲーション" });
  await expect(navigation.getByRole("link", { name: "ダッシュボード" })).toBeVisible();
  await expect(navigation.getByRole("link", { name: "カタログ概要" })).toHaveCount(0);
  await expect(navigation.getByRole("link", { name: "景品管理" })).toHaveCount(0);
  await expect(navigation.getByRole("link", { name: "QA管理" })).toHaveCount(0);
  await expect(navigation.getByRole("link", { name: "管理者認証" })).toHaveCount(0);
  await expect(navigation.getByRole("link", { name: "保有景品" })).toHaveAttribute(
    "href",
    "/user-prizes",
  );
  for (const [groupName, paths] of groups) {
    const button = navigation.getByRole("button", { name: groupName, exact: true });
    await button.click();
    await expect(button).toHaveAttribute("aria-expanded", "true");
    const controls = page.locator(`#${await button.getAttribute("aria-controls")}`);
    await expect(controls.getByRole("link")).toHaveCount(paths.length);
  }

  for (const [groupName, paths] of groups) {
    for (const path of paths) {
      const response = await page.goto(path);
      expect(response?.status()).toBe(200);
      await expect(page.getByRole("button", { name: groupName, exact: true })).toHaveAttribute(
        "aria-expanded",
        "true",
      );
      const active = navigation.locator('a[aria-current="page"]');
      await expect(active).toHaveAttribute("href", path);
      await expect(page.getByText("404")).toHaveCount(0);
    }
  }

  await page.goto("/catalog/gachas/new");
  await expect(navigation.locator('a[aria-current="page"]')).toHaveAttribute(
    "href",
    "/catalog/gachas/new",
  );
  await page.reload();
  await expect(page.getByRole("button", { name: "ガチャ", exact: true })).toHaveAttribute(
    "aria-expanded",
    "true",
  );
  expect(consoleErrors).toEqual([]);
});

test("Payment navigation follows Gacha and opens Payment status", async ({ page }) => {
  await page.goto("/");
  const navigation = page.getByRole("navigation", { name: "管理ナビゲーション" });
  const paymentGroup = navigation.getByRole("button", { name: "決済", exact: true });
  await expect(paymentGroup).toBeVisible();
  const topLevelLabels = (await navigation.locator(".nav-parent").allTextContents())
    .map((label) => label.trim());
  const gachaIndex = topLevelLabels.indexOf("ガチャ");
  expect(topLevelLabels.slice(gachaIndex, gachaIndex + 3)).toEqual([
    "ガチャ",
    "決済",
    "配送",
  ]);

  await paymentGroup.click();
  const paymentStatus = navigation.getByRole("link", { name: "決済状況" });
  await expect(paymentStatus).toHaveAttribute("href", "/payments");
  await paymentStatus.click();
  await expect(page).toHaveURL(/\/payments$/u);
  await expect(paymentGroup).toHaveAttribute("aria-expanded", "true");
  await expect(paymentStatus).toHaveAttribute("aria-current", "page");
});

test("mobile drawer closes on navigation and restores focus on Escape", async ({
  page,
}) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/users");
  const trigger = page.getByRole("button", { name: "ナビゲーションを開く" });
  await trigger.click();
  const sidebar = page.locator(".admin-sidebar");
  await expect(sidebar).toHaveClass(/is-open/u);
  await expect(page.getByRole("button", { name: "ユーザー", exact: true })).toHaveAttribute(
    "aria-expanded",
    "true",
  );
  await page.locator("#admin-nav-users").getByRole("link", { name: "履歴" }).click();
  await expect(page).toHaveURL(/\/users\/history$/u);
  await expect(sidebar).not.toHaveClass(/is-open/u);

  await trigger.click();
  await page.keyboard.press("Escape");
  await expect(sidebar).not.toHaveClass(/is-open/u);
  await expect(trigger).toBeFocused();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
});

test("Gacha core list, registration, detail links, and scaffolds remain usable", async ({
  page,
}) => {
  await installGachaCoreApi(page);
  await page.goto("/catalog/gachas");
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID",
    "ガチャ名",
    "サムネイル画像",
    "消費ポイント",
    "公開ステータス",
    "履歴",
    "詳細",
  ]);
  await expect(page.getByRole("link", { name: "Core Draftの履歴" })).toHaveAttribute(
    "href",
    `/catalog/gachas/${gachaId}/history`,
  );
  await page.getByRole("link", { name: "Core Draftの詳細" }).click();
  await expect(
    page.getByRole("heading", { level: 1, name: "Core Draft" }),
  ).toBeVisible();
  await expect(page.getByRole("link", { name: "利益シミュレーション" })).toBeVisible();
  await expect(page.getByRole("link", { name: "商品設計プランナー" })).toBeVisible();

  for (const [path, heading] of [
    [`/catalog/gachas/${gachaId}/history`, "ガチャ利用履歴"],
    [`/catalog/gachas/${gachaId}/profit-simulation`, "利益シミュレーション"],
    [`/catalog/gachas/${gachaId}/product-design-planner`, "商品設計プランナー"],
  ] as const) {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { name: heading, exact: true }).first()).toBeVisible();
    await expect(page.getByText("詳細画面は後続Taskで実装します。")).toBeVisible();
  }

  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/catalog/gachas/new");
  await expect(page.getByRole("heading", { name: "ガチャ登録", exact: true }).first()).toBeVisible();
  await expect(page.getByLabel("1日規定回数（0は無制限・JST 0時リセット）")).toHaveValue("0");
  await expect(page.getByLabel("状態")).toHaveValue("下書き");
  await expect(page.getByRole("button", { name: /公開/u })).toHaveCount(0);
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
});

test("Gacha rank and prize management remains usable on desktop and mobile", async ({ page }) => {
  await installGachaCoreApi(page);
  await installGachaRankPrizeApi(page);
  await page.goto(`/catalog/gachas/${gachaId}`);

  await expect(page.getByRole("heading", { name: "ランク／景品管理" })).toBeVisible();
  const rankPrizeSection = page.locator("section.catalog-rank-prize-section");
  await expect(rankPrizeSection.getByRole("columnheader")).toHaveText([
    "ID", "ランク", "景品名", "サムネイル", "総在庫数", "現在個数",
    "交換ポイント", "状態", "登録日", "編集",
  ]);
  await expect(rankPrizeSection.getByRole("cell", { exact: true, name: "7" })).toBeVisible();

  const rankButton = page.getByRole("button", { name: "ランク設定" });
  await rankButton.click();
  const rankDialog = page.getByRole("dialog", { name: "ランク設定" });
  await expect(rankDialog.getByText("Sランク", { exact: true })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(rankDialog).toHaveCount(0);
  await expect(rankButton).toBeFocused();

  await page.setViewportSize({ height: 844, width: 390 });
  await page.getByRole("button", { name: "新規景品登録" }).click();
  const prizeDialog = page.getByRole("dialog", { name: "新規景品登録" });
  await expect(prizeDialog.getByLabel("景品名")).toBeFocused();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
});

async function installPreviewApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: {
          id: "01910191-0191-7191-8191-019101910191",
          mfa_verified: true,
          role: "owner",
          state: "active",
        },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ownerPermissions,
        request_id: "01910191-0191-7191-8191-019101910193",
        role: "owner",
      });
    }
    if (path.endsWith("/reports/dashboard/sales/monthly")) {
      return json(route, {
        basis: "operational_event_aggregation_not_accounting_recognition",
        currency: "JPY",
        days: [],
        month: "2026-08",
        summary: {
          chargeback_amount: 0,
          chargeback_count: 0,
          gross_sales_amount: 0,
          net_sales_amount: 0,
          payment_count: 0,
          refund_amount: 0,
          refund_count: 0,
        },
        timezone: "Asia/Tokyo",
      });
    }
    if (path.endsWith("/users")) {
      return json(route, { items: [], next_cursor: null });
    }
    if (path.endsWith("/user-prizes")) {
      return json(route, { items: [], next_cursor: null, request_id: "01910191-0191-7191-8191-019101910193" });
    }
    if (/\/catalog\/(gachas|categories|tags|presentation-assets)$/u.test(path)) {
      return json(route, { items: [], next_cursor: null });
    }
    if (path.endsWith("/identity/line-messaging")) {
      return json(route, {
        data: {
          id: "01910191-0191-7191-8191-019101910199",
          linked_follow_message: "友だち追加が完了しました。",
          login_relative_path: "/login",
          pending_follow_message: "{login_url} からログインしてください。",
          reward_enabled: false,
          reward_expiration_days: 180,
          reward_point_amount: 0,
          revision: 1,
          updated_at: "2026-08-03T00:00:00Z",
        },
        request_id: "01910191-0191-7191-8191-019101910193",
      });
    }
    return route.fulfill({ status: 404 });
  });
}

async function installGachaCoreApi(page: Page): Promise<void> {
  await page.route("**/assets/core-draft.png", (route) =>
    route.fulfill({
      body: Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
        "base64",
      ),
      contentType: "image/png",
      status: 200,
    }),
  );
  await page.route(/\/admin\/api\/v2\/catalog\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/catalog/categories")) {
      return json(route, { items: [category()], next_cursor: null });
    }
    if (path.endsWith("/catalog/tags")) {
      return json(route, { items: [tag()], next_cursor: null });
    }
    if (path.endsWith("/catalog/presentation-assets")) {
      return json(route, { items: [asset()], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions`)) {
      return json(route, { items: [], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}`)) {
      return json(route, { data: gacha() });
    }
    if (path.endsWith("/catalog/gachas")) {
      return json(route, { items: [gacha()], next_cursor: null });
    }
    return route.fallback();
  });
}

async function installGachaRankPrizeApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/catalog\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions`)) {
      return json(route, { items: [gachaVersion()], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}/ranks`)) {
      return json(route, { items: [rank()], version_revision: 1 });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}/prizes`)) {
      return json(route, { items: [prize()], version_revision: 1 });
    }
    return route.fallback();
  });
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
    ...category(),
    code: "featured",
    id: tagId,
    name: "注目",
    slug: "featured",
  };
}

function asset() {
  return {
    alt_text: "Core Draft",
    byte_size: 128,
    checksum_sha256: "a".repeat(64),
    created_at: "2026-08-01T00:00:00Z",
    id: assetId,
    is_archived: false,
    is_public: true,
    media_type: "image",
    mime_type: "image/png",
    public_path: "/assets/core-draft.png",
    revision: 1,
    updated_at: "2026-08-01T00:00:00Z",
  };
}

function gacha() {
  return {
    archived_at: null,
    category: category(),
    code: "core-draft",
    created_at: "2026-08-01T00:00:00Z",
    current_version: {
      audience_code: "all_users",
      daily_draw_limit: 0,
      description: "Draft description",
      id: versionId,
      notices: "Draft notices",
      presentation_asset: asset(),
      price_points: 100,
      publish_end_at: "2026-09-01T00:00:00Z",
      publish_start_at: "2026-08-01T00:00:00Z",
      status: "draft",
      title: "Core Draft",
      total_count: 1000,
      version_number: 1,
    },
    has_draw_history: false,
    id: gachaId,
    is_archived: false,
    publication_status: "draft",
    published_version: null,
    revision: 1,
    slug: "core-draft",
    state: "draft",
    tags: [tag()],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}

function gachaVersion() {
  return {
    archived_at: null,
    audience_code: "all_users",
    cloned_from_version: null,
    created_at: "2026-08-01T00:00:00Z",
    daily_draw_limit: 0,
    description: "Draft description",
    id: versionId,
    is_archived: false,
    notices: "Draft notices",
    presentation_asset: asset(),
    price_points: 100,
    prizes: [],
    publish_end_at: "2026-09-01T00:00:00Z",
    publish_start_at: "2026-08-01T00:00:00Z",
    published_at: null,
    published_probability_version: null,
    revision: 1,
    status: "draft",
    title: "Core Draft",
    total_count: 1000,
    updated_at: "2026-08-01T00:00:00Z",
    version_number: 1,
  };
}

function rank() {
  return {
    archived_at: null,
    code: "s",
    created_at: "2026-08-01T00:00:00Z",
    description: "上位ランク",
    id: rankId,
    image_asset: asset(),
    is_archived: false,
    is_visible: true,
    name: "Sランク",
    revision: 1,
    sort_order: 0,
    updated_at: "2026-08-01T00:00:00Z",
    video_asset: null,
  };
}

function prize() {
  return {
    available_inventory: 7,
    code: "prize-s",
    cost_price: 3000,
    created_at: "2026-08-01T00:00:00Z",
    description: null,
    display_price: 0,
    exchange_points: 8000,
    id: prizeId,
    is_visible: true,
    name: "限定カード",
    presentation_asset: asset(),
    rank: { code: "s", id: rankId, name: "Sランク", sort_order: 0 },
    revision: 1,
    total_inventory: 10,
    updated_at: "2026-08-01T00:00:00Z",
    version_sort_order: 0,
  };
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: {
      "Cache-Control": "private, no-store",
      "Content-Type": "application/json",
    },
    status: 200,
  });
}
