import { expect, test, type Page, type Route } from "@playwright/test";

const userPrizeId = "01910191-0191-7191-8191-019101910191";
const userId = "01910191-0191-7191-8191-019101910192";

test.beforeEach(async ({ page }) => installApi(page));

test("desktop global User Prize list filters and opens canonical detail", async ({ page }) => {
  const errors = observeErrors(page);
  const response = await page.goto("/user-prizes");
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "保有景品一覧" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "User", "景品", "ランク", "取得元Gacha", "取得日時", "現在状態", "Fulfillment", "詳細",
  ]);
  await expect(page.getByText("E2E景品")).toBeVisible();
  await page.getByRole("textbox", { name: "ユーザー", exact: true }).fill("E2E User");
  await page.getByLabel("景品名").fill("E2E景品");
  await page.getByLabel("ガチャ").fill("E2EGACHA001");
  await page.getByLabel("状態").selectOption("stored");
  await page.getByRole("button", { name: "検索" }).click();
  await page.getByRole("link", { name: "E2E景品の詳細" }).click();
  await expect(page).toHaveURL(new RegExp(`/user-prizes/${userPrizeId}$`, "u"));
  await expect(page.getByRole("heading", { name: "景品情報" })).toBeVisible();
  await expect(page.getByText("要求 5／実行 5")).toBeVisible();
  await expect(page.getByRole("heading", { name: "現在可能な操作" })).toBeVisible();
  await expect(page.getByText("配送依頼はありません。")).toBeVisible();
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile list and detail do not overflow and expose no mutation", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  await page.goto("/user-prizes");
  await expect(page.locator(".admin-user-prize-table-region")).toHaveAttribute("tabindex", "0");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.goto(`/user-prizes/${userPrizeId}`);
  await expect(page.getByRole("heading", { name: "配送／ポイント交換" })).toBeVisible();
  await expect(page.locator("main").getByRole("button", { name: /配送|交換/u })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
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
      return json(route, {
        permissions: ["shipping.request.manage"],
        request_id: uuid("9"),
        role: "operator",
      });
    }
    if (path.endsWith(`/user-prizes/${userPrizeId}`)) return json(route, detail());
    if (path.endsWith("/user-prizes")) {
      if (url.searchParams.get("user")) {
        expect(url.searchParams.get("user")).toBe("E2E User");
        expect(url.searchParams.get("prize_name")).toBe("E2E景品");
        expect(url.searchParams.get("gacha")).toBe("E2EGACHA001");
        expect(url.searchParams.get("status")).toBe("stored");
      }
      return json(route, { items: [summary()], next_cursor: null, request_id: uuid("9") });
    }
    return route.fulfill({ status: 404 });
  });
}

function summary() {
  return {
    acquired_at: "2026-09-05T00:00:00Z",
    allowed_actions: {
      point_exchange: { allowed: true, unavailable_reason: null },
      selection: { allowed: true, unavailable_reason: null },
      shipping: { allowed: true, unavailable_reason: null },
    },
    exchange_points: 500,
    exchanged_points: null,
    fulfillment: { point_exchange_status: null, shipping_status: null },
    gacha: { id: "E2EGACHA001", title: "E2E Gacha", version_id: uuid("3") },
    id: userPrizeId,
    prize: {
      id: uuid("4"),
      image: null,
      name: "E2E景品",
      rank: { code: "S", id: uuid("5"), name: "Sランク" },
    },
    status: "stored",
    status_updated_at: "2026-09-05T00:00:00Z",
    storage_expires_at: "2026-11-04T00:00:00Z",
    terminal_at: null,
    user: { display_name: "E2E User", id: userId },
  };
}

function detail() {
  return {
    data: {
      ...summary(),
      draw: {
        completed_at: "2026-09-05T00:00:00Z",
        consumed_points: 500,
        executed_count: 5,
        request_id: uuid("6"),
        requested_count: 5,
        result_id: uuid("7"),
      },
      point_exchange: null,
      shipping: null,
      status_history: [{
        from_status: null,
        occurred_at: "2026-09-05T00:00:00Z",
        reason_code: "draw_acquired",
        to_status: "stored",
      }],
    },
    request_id: uuid("9"),
  };
}

function observeErrors(page: Page) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const gatewayErrors: number[] = [];
  page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); });
  return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors });
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" },
    status: 200,
  });
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
