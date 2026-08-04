import { expect, test, type Page, type Route } from "@playwright/test";

const gachaId = uuid("1");
const versionId = uuid("2");
const probabilityId = uuid("3");
const prizeA = uuid("4");
const prizeB = uuid("5");

test.beforeEach(async ({ page }) => installApi(page));

test("desktop profit simulation recalculates the canonical V1 result", async ({ page }) => {
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

  const response = await page.goto(`/catalog/gachas/${gachaId}/profit-simulation`);
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "利益シミュレーション" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "夏のガチャ" })).toBeVisible();
  await expect(page.getByText("¥50,000")).toBeVisible();
  await expect(page.getByText("¥14,700")).toHaveCount(2);
  await expect(page.getByText("70.6%")).toBeVisible();
  await page.getByLabel("1口価格（pt）").fill("600");
  await page.getByLabel("目標粗利率（%・任意）").fill("30");
  await page.getByRole("button", { name: "再計算" }).click();
  await expect(page.getByText("¥60,000")).toBeVisible();
  await expect(page.getByText("目標達成")).toBeVisible();
  await expect(page.getByRole("link", { name: "ガチャ詳細へ" }))
    .toHaveAttribute("href", `/catalog/gachas/${gachaId}`);
  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("mobile profit simulation remains usable without horizontal overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto(`/catalog/gachas/${gachaId}/profit-simulation`);
  await expect(page.getByRole("heading", { name: "利益シミュレーション" })).toBeVisible();
  await page.getByLabel("保証原価（円／口）").focus();
  await expect(page.getByLabel("保証原価（円／口）")).toBeFocused();
  await expect(page.getByRole("button", { name: "再計算" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
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
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}/probability-selection`)) {
      return json(route, { data: { selected_probability: { id: probabilityId } } });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}/probability-versions/${probabilityId}`)) {
      return json(route, { data: probability() });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}/prizes`)) {
      return json(route, {
        items: [prize(prizeA, 1000, 5, 3), prize(prizeB, 200, 10, 9)],
        version_revision: 1,
      });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}/versions`)) {
      return json(route, {
        items: [{
          id: versionId,
          is_archived: false,
          price_points: 500,
          status: "draft",
          title: "夏のガチャ",
          total_count: 100,
          version_number: 2,
        }],
        next_cursor: null,
      });
    }
    if (path.endsWith(`/catalog/gachas/${gachaId}`)) {
      return json(route, {
        data: {
          code: "summer",
          current_version: { title: "夏のガチャ" },
          id: gachaId,
          sold_count: 20,
        },
      });
    }
    return route.fulfill({ status: 404 });
  });
}

function prize(id: string, cost: number, total: number, available: number) {
  return {
    available_inventory: available,
    cost_price: cost,
    id,
    total_inventory: total,
  };
}

function probability() {
  return {
    id: probabilityId,
    stages: [{
      code: "default",
      entries: [target(prizeA, 100_000), target(prizeB, 200_000)],
      max_draw_number: 100,
      min_draw_number: 1,
      minimum_guarantee: {
        point_amount: 10,
        prize: null,
        probability_ppm: 700_000,
        result_type: "point_back",
      },
      name: "通常",
    }],
  };
}

function target(id: string, ppm: number) {
  return {
    point_amount: null,
    prize: { id },
    probability_ppm: ppm,
    result_type: "prize",
  };
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
