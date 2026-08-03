import { expect, test, type Page, type Route } from "@playwright/test";

const userId = "01910191-0191-7191-8191-019101910191";

test.beforeEach(async ({ page }) => {
  await installApi(page);
});

test("Operator reads User list, detail, and acquired-prize history", async ({ page }) => {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const gatewayErrors: number[] = [];
  page.on("console", (message) => {
    if (message.type() === "error") consoleErrors.push(message.text());
  });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => {
    if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status());
  });

  const response = await page.goto("/users");
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "ユーザー一覧" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID", "ユーザー名", "状態", "合計残高", "有償P", "無償P", "登録日", "詳細",
  ]);
  await expect(page.getByText("テストユーザー")).toBeVisible();
  await expect(page.getByText("300 pt")).toBeVisible();
  await expect(page.getByRole("button", { name: "ユーザー", exact: true }))
    .toHaveAttribute("aria-expanded", "true");
  await expect(page.locator("#admin-nav-users").getByRole("link", { name: "一覧" }))
    .toBeVisible();
  await expect(page.locator("#admin-nav-users").getByRole("link", { name: "履歴" }))
    .toHaveCount(0);

  await page.getByRole("link", { name: "テストユーザーの詳細" }).click();
  await expect(page).toHaveURL(new RegExp(`/users/${userId}$`, "u"));
  await expect(page.getByRole("heading", { name: "基本情報" })).toBeVisible();
  await expect(page.getByText("user@example.test")).toBeVisible();
  await expect(page.getByText("ユーザー保有景品")).toHaveCount(0);
  await page.getByRole("link", { name: "ガチャ履歴を表示" }).click();
  await expect(page).toHaveURL(new RegExp(`/users/${userId}/gacha-history$`, "u"));
  await expect(page.getByRole("heading", { name: "ユーザーガチャ履歴" })).toBeVisible();
  await expect(page.getByText("テストガチャ")).toBeVisible();
  await expect(page.getByText("景品A")).toBeVisible();
  await expect(page.getByRole("link", { name: "ユーザー詳細へ" })).toBeVisible();

  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("mobile User tables remain inside a keyboard-scrollable region", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/users");
  const region = page.locator(".admin-user-table-region");
  await expect(region).toHaveAttribute("tabindex", "0");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);

  const trigger = page.getByRole("button", { name: "ナビゲーションを開く" });
  await trigger.click();
  await page.keyboard.press("Escape");
  await expect(trigger).toBeFocused();
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: userId, mfa_verified: false, role: "operator", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, { permissions: [], request_id: uuid("9"), role: "operator" });
    }
    if (path.endsWith(`/users/${userId}/gacha-history`)) {
      return json(route, {
        items: [historyItem()], next_cursor: null, request_id: uuid("9"), user_id: userId,
      });
    }
    if (path.endsWith(`/users/${userId}`)) {
      return json(route, {
        data: {
          ...userSummary(),
          email: "user@example.test",
          email_verified_at: "2026-08-03T00:00:00Z",
          updated_at: "2026-08-03T01:00:00Z",
        },
        request_id: uuid("9"),
      });
    }
    if (path.endsWith("/users")) {
      return json(route, { items: [userSummary()], next_cursor: null, request_id: uuid("9") });
    }
    return route.fulfill({ status: 404 });
  });
}

function userSummary() {
  return {
    created_at: "2026-08-03T00:00:00Z",
    display_name: "テストユーザー",
    id: userId,
    point_balance: { free_balance: 200, paid_balance: 100, total_balance: 300 },
    status: "active",
  };
}

function historyItem() {
  return {
    acquired_at: "2026-08-03T00:00:00Z",
    draw_result_id: uuid("2"),
    exchange_point_snapshot: 500,
    exchanged_point_amount: null,
    gacha_id: uuid("3"),
    gacha_title: "テストガチャ",
    gacha_version_id: uuid("4"),
    id: uuid("5"),
    prize_id: uuid("6"),
    prize_name: "景品A",
    rank_id: uuid("7"),
    rank_name: "Sランク",
    status: "stored",
    storage_expires_at: "2026-10-02T00:00:00Z",
    terminal_at: null,
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" },
    status: 200,
  });
}
