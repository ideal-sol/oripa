import { expect, test, type Page, type Route } from "@playwright/test";

const gachaId = uuid("1");
const requestId = uuid("2");

test.beforeEach(async ({ page }) => installApi(page));

test("desktop Gacha usage history opens the complete prize detail", async ({ page }) => {
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

  const response = await page.goto(`/catalog/gachas/${gachaId}/history`);
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "ガチャ利用履歴" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "ガチャ利用ID", "ユーザー名", "何連ガチャ", "状態", "ガチャ利用日時", "詳細",
  ]);
  await expect(page.getByText("配送 2")).toBeVisible();
  await expect(page.getByText("ポイント交換 8")).toBeVisible();
  await page.getByRole("link", { name: `${requestId}の詳細` }).click();
  await expect(page).toHaveURL(new RegExp(`/catalog/gachas/${gachaId}/history/${requestId}$`, "u"));
  await expect(page.getByRole("heading", { name: "ガチャ利用詳細" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "当選景品一覧" })).toBeVisible();
  await expect(page.getByText("S賞")).toBeVisible();
  await expect(page.getByText("A賞")).toBeVisible();
  await expect(page.getByRole("link", { name: "履歴一覧へ" })).toBeVisible();
  await expect(page.getByRole("link", { name: "対象ガチャ詳細へ" })).toBeVisible();

  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("mobile history tables remain keyboard scrollable without page overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto(`/catalog/gachas/${gachaId}/history`);
  const region = page.locator(".catalog-table-wrap");
  await expect(region).toHaveAttribute("tabindex", "0");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("9"), mfa_verified: false, role: "operator", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, { permissions: ["catalog.read"], request_id: uuid("9"), role: "operator" });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/history/${requestId}`)) {
      return json(route, {
        data: {
          consumed_points: 1000,
          executed_count: 10,
          gacha: { id: gachaId, title: "夏のガチャ", version_id: uuid("5") },
          id: requestId,
          prizes: [prize("6", "S賞", "stored"), prize("7", "A賞", "converted")],
          status_summary: [
            { count: 1, status: "selection_pending" },
            { count: 1, status: "point_exchange" },
          ],
          used_at: "2026-08-04T00:00:00Z",
          user: { display_name: "テストユーザー", id: uuid("3") },
        },
        request_id: uuid("4"),
      });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/history`)) {
      return json(route, {
        gacha_id: gachaId,
        items: [{
          executed_count: 10,
          id: requestId,
          status_summary: [
            { count: 2, status: "shipping" },
            { count: 8, status: "point_exchange" },
          ],
          used_at: "2026-08-04T00:00:00Z",
          user: { display_name: "テストユーザー", id: uuid("3") },
        }],
        next_cursor: null,
        request_id: uuid("4"),
      });
    }
    return route.fulfill({ status: 404 });
  });
}

function prize(last: string, name: string, status: "stored" | "converted") {
  return {
    draw_result_id: uuid(last),
    exchange_points: 500,
    prize_id: uuid(last),
    prize_name: name,
    rank: { id: uuid("8"), name: "Sランク" },
    sequence: Number(last),
    status,
    status_updated_at: "2026-08-04T00:01:00Z",
    thumbnail: null,
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
