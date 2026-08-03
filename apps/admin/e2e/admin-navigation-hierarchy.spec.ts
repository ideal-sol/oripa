import { expect, test, type Page, type Route } from "@playwright/test";

const ownerPermissions = [
  "identity.admin.read",
  "identity.admin.manage",
  "identity.admin.session.revoke",
  "identity.line.manage",
  "point.ledger.read",
  "point.adjustment.request",
  "point.adjustment.free.approve",
  "point.adjustment.paid.approve",
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
